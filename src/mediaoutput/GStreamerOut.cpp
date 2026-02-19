/*
 * This file is part of the Falcon Player (FPP) and is Copyright (C)
 * 2013-2025 by the Falcon Player Developers.
 *
 * The Falcon Player (FPP) is free software, and is covered under
 * multiple Open Source licenses.  Please see the included 'LICENSES'
 * file for descriptions of what files are covered by each license.
 *
 * This source file is covered under the LGPL v2.1 as described in the
 * included LICENSE.LGPL file.
 */

#include "fpp-pch.h"

#include "GStreamerOut.h"

#ifdef HAS_GSTREAMER

#include <gst/gst.h>
#include <gst/app/gstappsink.h>
#include <cstdlib>
#include <cstring>
#include <dirent.h>
#include <fstream>
#include <sstream>

#include "common.h"
#include "log.h"
#include "mediadetails.h"
#include "settings.h"
#include "channeloutput/channeloutputthread.h"
#include "overlays/PixelOverlay.h"
#include "overlays/PixelOverlayModel.h"

// Static instance pointer for callbacks
GStreamerOutput* GStreamerOutput::m_currentInstance = nullptr;

// Static audio sample buffer for WLED audio-reactive effects
std::array<float, GStreamerOutput::SAMPLE_BUFFER_SIZE> GStreamerOutput::s_sampleBuffer = {};
int GStreamerOutput::s_sampleWritePos = 0;
int GStreamerOutput::s_sampleRate = 0;
std::mutex GStreamerOutput::s_sampleMutex;

// One-time GStreamer initialization
static bool gst_initialized = false;
void GStreamerOutput::EnsureGStreamerInit() {
    if (!gst_initialized) {
        LogWarn(VB_MEDIAOUT, "GStreamer: EnsureGStreamerInit() entered\n");
        // Set PipeWire env vars so pipewiresink can find the FPP PipeWire runtime
        std::string audioBackend = getSetting("AudioBackend");
        if (audioBackend == "pipewire") {
            setenv("PIPEWIRE_RUNTIME_DIR", "/run/pipewire-fpp", 1);
            setenv("XDG_RUNTIME_DIR", "/run/pipewire-fpp", 1);
            setenv("PULSE_RUNTIME_PATH", "/run/pipewire-fpp/pulse", 1);
            LogWarn(VB_MEDIAOUT, "GStreamer: Set PipeWire env (PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp)\n");
        } else {
            LogWarn(VB_MEDIAOUT, "GStreamer: AudioBackend='%s', not setting PipeWire env\n", audioBackend.c_str());
        }
        LogWarn(VB_MEDIAOUT, "GStreamer: Calling gst_init()...\n");
        gst_init(nullptr, nullptr);
        gst_initialized = true;
        LogWarn(VB_MEDIAOUT, "GStreamer initialized: %s\n", gst_version_string());
    }
}

// Resolve a DRM connector name (e.g., "HDMI-A-1") to its card path, connector ID,
// connection status, and current display resolution by scanning sysfs.
// Works on all Pi models (Pi 3/4/5) and x86 — no libdrm ioctls needed.
GStreamerOutput::DrmConnectorInfo GStreamerOutput::ResolveDrmConnector(const std::string& connectorName) {
    DrmConnectorInfo info;

    // Scan /sys/class/drm/ for cardN-<connectorName>
    for (int cardNum = 0; cardNum < 8; cardNum++) {
        std::string sysBase = "/sys/class/drm/card" + std::to_string(cardNum) + "-" + connectorName;
        std::string statusPath = sysBase + "/status";
        if (!FileExists(statusPath))
            continue;

        info.cardPath = "/dev/dri/card" + std::to_string(cardNum);

        // Read connection status
        std::string status = GetFileContents(statusPath);
        info.connected = (status.find("connected") != std::string::npos &&
                          status.find("disconnected") == std::string::npos);

        // Read connector ID (available since Linux 5.x)
        std::string cidPath = sysBase + "/connector_id";
        if (FileExists(cidPath)) {
            std::string cidStr = GetFileContents(cidPath);
            info.connectorId = atoi(cidStr.c_str());
        }

        // Read display resolution from first available mode
        std::string modesPath = sysBase + "/modes";
        if (FileExists(modesPath)) {
            std::ifstream mf(modesPath);
            std::string firstMode;
            if (std::getline(mf, firstMode) && !firstMode.empty()) {
                // Format: "1920x1080" or "1280x720"
                size_t xpos = firstMode.find('x');
                if (xpos != std::string::npos) {
                    info.displayWidth = atoi(firstMode.substr(0, xpos).c_str());
                    info.displayHeight = atoi(firstMode.substr(xpos + 1).c_str());
                }
            }
        }

        LogInfo(VB_MEDIAOUT, "GStreamer DRM: %s on card%d connector_id=%d connected=%d display=%dx%d\n",
                connectorName.c_str(), cardNum, info.connectorId, info.connected,
                info.displayWidth, info.displayHeight);
        break;
    }

    return info;
}

GStreamerOutput::GStreamerOutput(const std::string& mediaFilename, MediaOutputStatus* status, const std::string& videoOut)
    : m_videoOut(videoOut) {
    LogWarn(VB_MEDIAOUT, "GStreamer: CTOR enter (%s, videoOut=%s)\n", mediaFilename.c_str(), videoOut.c_str());
    m_mediaFilename = mediaFilename;
    m_mediaOutputStatus = status;
    m_allowSpeedAdjust = (getSettingInt("remoteIgnoreSync") == 0);
    EnsureGStreamerInit();
    LogWarn(VB_MEDIAOUT, "GStreamer: CTOR done (%s)\n", mediaFilename.c_str());
}

GStreamerOutput::~GStreamerOutput() {
    Close();
}

int GStreamerOutput::Start(int msTime) {
    LogWarn(VB_MEDIAOUT, "GStreamer: Start(%d) enter - %s\n", msTime, m_mediaFilename.c_str());

    // Reset MultiSync rate-matching state for the new track
    m_currentRate = 1.0f;
    m_diffsSize = 0;
    m_diffIdx = 0;
    m_diffSum = 0;
    m_rateSum = 0.0f;
    m_lastDiff = -1;
    m_rateDiff = 0;
    m_lastRates.clear();
    m_lastRatesSum = 0.0f;

    // Build full path — check music dir, then video dir (mirrors SDLOutput)
    std::string fullPath = m_mediaFilename;
    if (!FileExists(fullPath)) {
        fullPath = FPP_DIR_MUSIC("/" + m_mediaFilename);
    }
    if (!FileExists(fullPath)) {
        fullPath = FPP_DIR_VIDEO("/" + m_mediaFilename);
    }
    if (!FileExists(fullPath)) {
        LogErr(VB_MEDIAOUT, "GStreamer: media file not found: %s\n", m_mediaFilename.c_str());
        return 0;
    }

    // Pre-populate duration from file metadata so that
    // PlaylistEntryMedia::GetLengthInMS() returns a valid value immediately.
    // Without this, the playlist status polls GetElapsedMS() before GStreamer
    // has queried the duration, causing m_duration to track elapsed time
    // and seconds_remaining to always report 0.
    {
        MediaDetails details;
        details.ParseMedia(fullPath.c_str());
        if (details.lengthMS > 0) {
            int totalSecs = details.lengthMS / 1000;
            m_mediaOutputStatus->minutesTotal = totalSecs / 60;
            m_mediaOutputStatus->secondsTotal = totalSecs % 60;
            m_maxDuration = (gint64)details.lengthMS * GST_MSECOND;
            LogInfo(VB_MEDIAOUT, "GStreamer: pre-set duration from metadata: %d:%02d (%d ms)\n",
                    m_mediaOutputStatus->minutesTotal, m_mediaOutputStatus->secondsTotal, details.lengthMS);
        }
    }

    // Determine if we need a video overlay branch or HDMI output
    bool wantVideo = false;  // PixelOverlay mode
    bool wantHDMI = false;   // DRM/KMS HDMI mode

    if (m_videoOut != "--Disabled--" && !m_videoOut.empty()) {
        // Check if this is an HDMI/DRM output connector
        if (m_videoOut.starts_with("HDMI-") || m_videoOut.starts_with("DSI-") ||
            m_videoOut.starts_with("Composite-") ||
            m_videoOut == "--HDMI--" || m_videoOut == "--hdmi--" || m_videoOut == "HDMI") {
            // Resolve the connector name
            std::string connectorName = m_videoOut;
            if (connectorName == "--HDMI--" || connectorName == "--hdmi--" || connectorName == "HDMI") {
                connectorName = "HDMI-A-1";
            }
            DrmConnectorInfo drmInfo = ResolveDrmConnector(connectorName);
            if (drmInfo.connected && drmInfo.connectorId >= 0) {
                m_wantHDMI = true;
                wantHDMI = true;
                m_hdmiConnectorId = drmInfo.connectorId;
                m_hdmiCardPath = drmInfo.cardPath;
                m_hdmiDisplayWidth = drmInfo.displayWidth;
                m_hdmiDisplayHeight = drmInfo.displayHeight;
                LogInfo(VB_MEDIAOUT, "GStreamer HDMI output: connector=%s id=%d card=%s resolution=%dx%d\n",
                        connectorName.c_str(), m_hdmiConnectorId, m_hdmiCardPath.c_str(),
                        m_hdmiDisplayWidth, m_hdmiDisplayHeight);
            } else if (!drmInfo.connected) {
                LogWarn(VB_MEDIAOUT, "GStreamer: %s is not connected, disabling video\n", connectorName.c_str());
            } else {
                LogWarn(VB_MEDIAOUT, "GStreamer: could not resolve connector ID for %s\n", connectorName.c_str());
            }
        } else {
            // PixelOverlay model name
            wantVideo = true;
        }
    }

    if (wantVideo) {
        // Register listener and get model
        PixelOverlayManager::INSTANCE.addModelListener(m_videoOut, "GStreamerOut",
            [this](PixelOverlayModel* m) {
                std::lock_guard<std::mutex> lock(m_videoOverlayModelLock);
                m_videoOverlayModel = m;
            });
        m_videoOverlayModel = PixelOverlayManager::INSTANCE.getModel(m_videoOut);
        if (m_videoOverlayModel) {
            m_videoOverlayModel->getSize(m_videoOverlayWidth, m_videoOverlayHeight);
            LogInfo(VB_MEDIAOUT, "GStreamer video overlay: model=%s size=%dx%d\n",
                    m_videoOut.c_str(), m_videoOverlayWidth, m_videoOverlayHeight);
        } else {
            LogWarn(VB_MEDIAOUT, "GStreamer: PixelOverlay model '%s' not found, skipping video\n",
                    m_videoOut.c_str());
            wantVideo = false;
        }
    }

    // Build the pipeline
    // Audio: filesrc ! decodebin ! audioconvert ! audioresample ! tee name=t
    //   t. ! queue ! volume ! pipewiresink
    //   t. ! queue ! audioconvert ! F32LE,1ch ! appsink (WLED tap)
    // Video (when overlay model available):
    //   decodebin pad-added -> videoconvert ! videoscale ! capsfilter(RGB,WxH) ! appsink
    //
    // When video is needed, we must use decodebin's pad-added signal for dynamic linking,
    // since decodebin creates pads on-the-fly for each stream type.
    // We still use gst_parse_launch for the audio chain and manually add the video chain.

    LogWarn(VB_MEDIAOUT, "GStreamer: Start() building pipeline...");
    std::string pipelineSinkName = getSetting("PipeWireSinkName");
    LogWarn(VB_MEDIAOUT, "GStreamer: PipeWireSinkName='%s'\n", pipelineSinkName.c_str());

    std::string sinkStr;
    if (!pipelineSinkName.empty()) {
        sinkStr = "pipewiresink name=pwsink target-object=" + pipelineSinkName;
    } else {
        sinkStr = "autoaudiosink";
    }

    GError* error = nullptr;

    if (wantVideo) {
        // Build pipeline with decodebin pad-added for dynamic audio+video linking
        std::string pipelineStr =
            "filesrc location=\"" + fullPath + "\" ! decodebin name=decoder";

        LogDebug(VB_MEDIAOUT, "GStreamer pipeline (video): %s\n", pipelineStr.c_str());
        m_pipeline = gst_parse_launch(pipelineStr.c_str(), &error);
        if (error) {
            LogErr(VB_MEDIAOUT, "GStreamer pipeline error: %s\n", error->message);
            g_error_free(error);
            return 0;
        }

        // Build audio sub-chain: audioconvert ! audioresample ! tee ! ...
        GstElement* audioconvert = gst_element_factory_make("audioconvert", "aconv");
        GstElement* audioresample = gst_element_factory_make("audioresample", "aresample");
        GstElement* tee = gst_element_factory_make("tee", "t");
        GstElement* queue1 = gst_element_factory_make("queue", "q1");
        m_volume = gst_element_factory_make("volume", "vol");
        GstElement* sink = nullptr;
        if (!pipelineSinkName.empty()) {
            sink = gst_element_factory_make("pipewiresink", "pwsink");
            g_object_set(sink, "target-object", pipelineSinkName.c_str(), NULL);
        } else {
            sink = gst_element_factory_make("autoaudiosink", "audiosink");
        }
        GstElement* queue2 = gst_element_factory_make("queue", "q2");
        GstElement* audioconvert2 = gst_element_factory_make("audioconvert", "aconv2");
        GstElement* capsfilterAudio = gst_element_factory_make("capsfilter", "acapsf");
        m_appsink = gst_element_factory_make("appsink", "sampletap");

        // Set audio tap caps: F32LE mono
        GstCaps* audioCaps = gst_caps_new_simple("audio/x-raw",
            "format", G_TYPE_STRING, "F32LE",
            "channels", G_TYPE_INT, 1, NULL);
        g_object_set(capsfilterAudio, "caps", audioCaps, NULL);
        gst_caps_unref(audioCaps);

        // Configure queues
        g_object_set(queue2, "max-size-buffers", 3, "leaky", 2 /* downstream */, NULL);

        // Configure audio appsink
        g_object_set(m_appsink, "emit-signals", TRUE, "sync", FALSE,
                     "max-buffers", 3, "drop", TRUE, NULL);

        // Force 48kHz output so PipeWire doesn't need to resample
        GstElement* rateCapsfilter = gst_element_factory_make("capsfilter", "ratecaps");
        GstCaps* rateCaps = gst_caps_new_simple("audio/x-raw",
            "rate", G_TYPE_INT, 48000, NULL);
        g_object_set(rateCapsfilter, "caps", rateCaps, NULL);
        gst_caps_unref(rateCaps);

        // Add audio elements to pipeline
        gst_bin_add_many(GST_BIN(m_pipeline), audioconvert, audioresample, rateCapsfilter, tee,
                         queue1, m_volume, sink,
                         queue2, audioconvert2, capsfilterAudio, m_appsink, NULL);

        // Take our own refs on elements we keep pointers to.
        // gst_bin_add sinks the floating ref; we need our own ref so Close() can safely unref.
        gst_object_ref(m_volume);
        gst_object_ref(m_appsink);

        // Link audio chain
        if (!gst_element_link_many(audioconvert, audioresample, rateCapsfilter, tee, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer: Failed to link audioconvert->audioresample->ratecaps->tee\n");
        }
        if (!gst_element_link_many(queue1, m_volume, sink, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer: Failed to link queue1->volume->sink\n");
        }
        if (!gst_element_link_many(queue2, audioconvert2, capsfilterAudio, m_appsink, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer: Failed to link queue2->audioconvert2->capsfilter->appsink\n");
        }

        // Link tee to both queues
        GstPad* teeSrc1 = gst_element_request_pad_simple(tee, "src_%u");
        GstPad* q1Sink = gst_element_get_static_pad(queue1, "sink");
        gst_pad_link(teeSrc1, q1Sink);
        gst_object_unref(teeSrc1);
        gst_object_unref(q1Sink);

        GstPad* teeSrc2 = gst_element_request_pad_simple(tee, "src_%u");
        GstPad* q2Sink = gst_element_get_static_pad(queue2, "sink");
        gst_pad_link(teeSrc2, q2Sink);
        gst_object_unref(teeSrc2);
        gst_object_unref(q2Sink);

        // Remember the audio chain entry point for pad-added linkage
        m_audioChain = audioconvert;

        // Attach AES67 zero-hop RTP branches if any send instances are active
#ifdef HAS_AES67_GSTREAMER
        AttachAES67Branches(tee);
#endif

        // Build video sub-chain: videoconvert ! videoscale ! capsfilter(RGB,WxH) ! appsink
        GstElement* videoconvert = gst_element_factory_make("videoconvert", "vconv");
        GstElement* videoscale = gst_element_factory_make("videoscale", "vscale");
        GstElement* capsfilterVideo = gst_element_factory_make("capsfilter", "vcapsf");
        GstElement* videoQueue = gst_element_factory_make("queue", "vq");
        m_videoAppsink = gst_element_factory_make("appsink", "videosink");

        // Set video caps: RGB at overlay model dimensions
        GstCaps* videoCaps = gst_caps_new_simple("video/x-raw",
            "format", G_TYPE_STRING, "RGB",
            "width", G_TYPE_INT, m_videoOverlayWidth,
            "height", G_TYPE_INT, m_videoOverlayHeight, NULL);
        g_object_set(capsfilterVideo, "caps", videoCaps, NULL);
        gst_caps_unref(videoCaps);

        // Configure video appsink: emit signals, sync=TRUE to pace delivery at real-time speed.
        // Without sync=TRUE, all frames are consumed immediately and position jumps to EOF,
        // falsely triggering stall detection (especially for video-only files).
        // drop=TRUE ensures old frames are discarded if ProcessVideoOverlay can't keep up.
        g_object_set(m_videoAppsink, "emit-signals", TRUE, "sync", TRUE,
                     "max-buffers", 2, "drop", TRUE, NULL);

        // Add video elements to pipeline
        gst_bin_add_many(GST_BIN(m_pipeline), videoQueue, videoconvert, videoscale,
                         capsfilterVideo, m_videoAppsink, NULL);

        // Take our own ref on the video appsink pointer
        gst_object_ref(m_videoAppsink);

        // Link video chain
        if (!gst_element_link_many(videoQueue, videoconvert, videoscale, capsfilterVideo,
                              m_videoAppsink, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer: Failed to link video chain\n");
        }

        // Remember the video chain entry point for pad-added linkage
        m_videoChain = videoQueue;

        // Connect decodebin pad-added signal for dynamic linking
        GstElement* decoder = gst_bin_get_by_name(GST_BIN(m_pipeline), "decoder");
        g_signal_connect(decoder, "pad-added", G_CALLBACK(OnPadAdded), this);
        g_signal_connect(decoder, "no-more-pads", G_CALLBACK(OnNoMorePads), this);
        gst_object_unref(decoder);

    } else if (wantHDMI) {
        // ---------------------------------------------------------------
        // HDMI/DRM video output via kmssink (Phase 4)
        // Pipeline: filesrc ! decodebin name=decoder
        //   Audio: pad-added -> audioconvert ! audioresample ! tee
        //     tee -> queue ! volume ! pipewiresink
        //     tee -> queue ! audioconvert ! F32LE,1ch ! appsink (WLED tap)
        //   Video: pad-added -> queue ! videoconvert ! videoscale ! capsfilter ! kmssink
        //
        // decodebin auto-selects the best available decoder:
        //   Pi 5: v4l2slh265dec for H.265, avdec_h264 for H.264
        //   Pi 4: v4l2 stateless H.264 if kernel supports, else avdec_h264
        //   Pi 3/Zero2: avdec_h264 software decode
        //   All platforms: avdec_h264 from gstreamer1.0-libav as universal fallback
        // ---------------------------------------------------------------
        std::string pipelineStr =
            "filesrc location=\"" + fullPath + "\" ! decodebin name=decoder";

        LogDebug(VB_MEDIAOUT, "GStreamer pipeline (HDMI): %s  connector-id=%d card=%s\n",
                 pipelineStr.c_str(), m_hdmiConnectorId, m_hdmiCardPath.c_str());
        m_pipeline = gst_parse_launch(pipelineStr.c_str(), &error);
        if (error) {
            LogErr(VB_MEDIAOUT, "GStreamer HDMI pipeline error: %s\n", error->message);
            g_error_free(error);
            return 0;
        }

        // Build audio sub-chain (same as overlay mode)
        GstElement* audioconvert = gst_element_factory_make("audioconvert", "aconv");
        GstElement* audioresample = gst_element_factory_make("audioresample", "aresample");
        GstElement* tee = gst_element_factory_make("tee", "t");
        GstElement* queue1 = gst_element_factory_make("queue", "q1");
        m_volume = gst_element_factory_make("volume", "vol");
        GstElement* sink = nullptr;
        if (!pipelineSinkName.empty()) {
            sink = gst_element_factory_make("pipewiresink", "pwsink");
            g_object_set(sink, "target-object", pipelineSinkName.c_str(), NULL);
        } else {
            sink = gst_element_factory_make("autoaudiosink", "audiosink");
        }
        GstElement* queue2 = gst_element_factory_make("queue", "q2");
        GstElement* audioconvert2 = gst_element_factory_make("audioconvert", "aconv2");
        GstElement* capsfilterAudio = gst_element_factory_make("capsfilter", "acapsf");
        m_appsink = gst_element_factory_make("appsink", "sampletap");

        // Set audio tap caps: F32LE mono
        GstCaps* audioCaps = gst_caps_new_simple("audio/x-raw",
            "format", G_TYPE_STRING, "F32LE",
            "channels", G_TYPE_INT, 1, NULL);
        g_object_set(capsfilterAudio, "caps", audioCaps, NULL);
        gst_caps_unref(audioCaps);

        g_object_set(queue2, "max-size-buffers", 3, "leaky", 2 /* downstream */, NULL);
        g_object_set(m_appsink, "emit-signals", TRUE, "sync", FALSE,
                     "max-buffers", 3, "drop", TRUE, NULL);

        // Force 48kHz output so PipeWire doesn't need to resample
        GstElement* rateCapsfilter = gst_element_factory_make("capsfilter", "ratecaps");
        GstCaps* rateCaps = gst_caps_new_simple("audio/x-raw",
            "rate", G_TYPE_INT, 48000, NULL);
        g_object_set(rateCapsfilter, "caps", rateCaps, NULL);
        gst_caps_unref(rateCaps);

        gst_bin_add_many(GST_BIN(m_pipeline), audioconvert, audioresample, rateCapsfilter, tee,
                         queue1, m_volume, sink,
                         queue2, audioconvert2, capsfilterAudio, m_appsink, NULL);

        gst_object_ref(m_volume);
        gst_object_ref(m_appsink);

        if (!gst_element_link_many(audioconvert, audioresample, rateCapsfilter, tee, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer HDMI: Failed to link audioconvert->audioresample->ratecaps->tee\n");
        }
        if (!gst_element_link_many(queue1, m_volume, sink, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer HDMI: Failed to link queue1->volume->sink\n");
        }
        if (!gst_element_link_many(queue2, audioconvert2, capsfilterAudio, m_appsink, NULL)) {
            LogErr(VB_MEDIAOUT, "GStreamer HDMI: Failed to link audio appsink chain\n");
        }

        GstPad* teeSrc1 = gst_element_request_pad_simple(tee, "src_%u");
        GstPad* q1Sink = gst_element_get_static_pad(queue1, "sink");
        gst_pad_link(teeSrc1, q1Sink);
        gst_object_unref(teeSrc1);
        gst_object_unref(q1Sink);

        GstPad* teeSrc2 = gst_element_request_pad_simple(tee, "src_%u");
        GstPad* q2Sink = gst_element_get_static_pad(queue2, "sink");
        gst_pad_link(teeSrc2, q2Sink);
        gst_object_unref(teeSrc2);
        gst_object_unref(q2Sink);

        m_audioChain = audioconvert;

        // Attach AES67 zero-hop RTP branches if any send instances are active
#ifdef HAS_AES67_GSTREAMER
        AttachAES67Branches(tee);
#endif

        // Build video sub-chain: queue ! videoconvert ! videoscale ! capsfilter ! kmssink
        GstElement* videoQueue = gst_element_factory_make("queue", "vq");
        GstElement* videoconvert = gst_element_factory_make("videoconvert", "vconv");
        GstElement* videoscale = gst_element_factory_make("videoscale", "vscale");
        m_kmssink = gst_element_factory_make("kmssink", "kmsvideosink");

        if (!m_kmssink) {
            LogErr(VB_MEDIAOUT, "GStreamer: kmssink element not available — is gstreamer1.0-plugins-bad installed?\n");
            gst_object_unref(m_pipeline);
            m_pipeline = nullptr;
            return 0;
        }

        // Configure kmssink:
        //   driver-name: "vc4" on all Pi models (vc4-kms-v3d driver)
        //   connector-id: resolved from sysfs for the specific HDMI port
        //   restore-crtc: restore the console/framebuffer mode on stop
        //   skip-vsync: true for atomic drivers (vc4) to avoid double vsync
        g_object_set(m_kmssink,
                     "driver-name", "vc4",
                     "connector-id", m_hdmiConnectorId,
                     "restore-crtc", TRUE,
                     "skip-vsync", TRUE,
                     NULL);

        // Scale video to display resolution if known — fills the entire screen.
        // If display resolution is unknown, let kmssink negotiate (may not fill screen).
        GstElement* capsfilterVideo = nullptr;
        if (m_hdmiDisplayWidth > 0 && m_hdmiDisplayHeight > 0) {
            capsfilterVideo = gst_element_factory_make("capsfilter", "vcapsf");
            GstCaps* videoCaps = gst_caps_new_simple("video/x-raw",
                "width", G_TYPE_INT, m_hdmiDisplayWidth,
                "height", G_TYPE_INT, m_hdmiDisplayHeight, NULL);
            g_object_set(capsfilterVideo, "caps", videoCaps, NULL);
            gst_caps_unref(videoCaps);
        }

        // Add video elements to pipeline
        if (capsfilterVideo) {
            gst_bin_add_many(GST_BIN(m_pipeline), videoQueue, videoconvert, videoscale,
                             capsfilterVideo, m_kmssink, NULL);
            if (!gst_element_link_many(videoQueue, videoconvert, videoscale,
                                       capsfilterVideo, m_kmssink, NULL)) {
                LogErr(VB_MEDIAOUT, "GStreamer HDMI: Failed to link video chain\n");
            }
        } else {
            gst_bin_add_many(GST_BIN(m_pipeline), videoQueue, videoconvert, videoscale,
                             m_kmssink, NULL);
            if (!gst_element_link_many(videoQueue, videoconvert, videoscale, m_kmssink, NULL)) {
                LogErr(VB_MEDIAOUT, "GStreamer HDMI: Failed to link video chain (no scaling)\n");
            }
        }

        m_videoChain = videoQueue;
        m_hasVideoStream = true;  // expect video stream from decodebin

        // Connect decodebin pad-added signal for dynamic linking
        GstElement* decoder = gst_bin_get_by_name(GST_BIN(m_pipeline), "decoder");
        g_signal_connect(decoder, "pad-added", G_CALLBACK(OnPadAdded), this);
        g_signal_connect(decoder, "no-more-pads", G_CALLBACK(OnNoMorePads), this);
        gst_object_unref(decoder);

    } else {
        // Audio-only pipeline (original gst_parse_launch approach)
        LogWarn(VB_MEDIAOUT, "GStreamer: Building audio-only pipeline\n");
        std::string pipelineStr =
            "filesrc location=\"" + fullPath + "\" ! decodebin ! audioconvert ! audioresample ! "
            "audio/x-raw,rate=48000 ! "
            "tee name=t "
            "t. ! queue ! volume name=vol ! " + sinkStr + " "
            "t. ! queue max-size-buffers=3 leaky=downstream ! "
            "audioconvert ! audio/x-raw,format=F32LE,channels=1 ! "
            "appsink name=sampletap emit-signals=true sync=false max-buffers=3 drop=true";

        LogWarn(VB_MEDIAOUT, "GStreamer pipeline: %s\n", pipelineStr.c_str());

        LogWarn(VB_MEDIAOUT, "GStreamer: Calling gst_parse_launch()...\n");
        m_pipeline = gst_parse_launch(pipelineStr.c_str(), &error);
        LogWarn(VB_MEDIAOUT, "GStreamer: gst_parse_launch() returned (pipeline=%p, error=%p)\n", m_pipeline, error);
        if (error) {
            LogErr(VB_MEDIAOUT, "GStreamer pipeline error: %s\n", error->message);
            g_error_free(error);
            return 0;
        }

        // Get the volume element for later control
        m_volume = gst_bin_get_by_name(GST_BIN(m_pipeline), "vol");

        // Get the appsink
        m_appsink = gst_bin_get_by_name(GST_BIN(m_pipeline), "sampletap");

        // Attach AES67 zero-hop RTP branches to the audio tee
#ifdef HAS_AES67_GSTREAMER
        {
            GstElement* tee = gst_bin_get_by_name(GST_BIN(m_pipeline), "t");
            if (tee) {
                AttachAES67Branches(tee);
                gst_object_unref(tee);
            }
        }
#endif
    }

    if (!m_pipeline) {
        LogErr(VB_MEDIAOUT, "Failed to create GStreamer pipeline\n");
        return 0;
    }

    // Connect audio appsink callback
    m_shutdownFlag.store(false);
    if (m_appsink) {
        m_appsinkSignalId = g_signal_connect(m_appsink, "new-sample", G_CALLBACK(OnNewSample), this);
        LogDebug(VB_MEDIAOUT, "GStreamer audio sample tap connected\n");
    } else {
        m_appsinkSignalId = 0;
        LogWarn(VB_MEDIAOUT, "GStreamer: could not find sampletap appsink element\n");
    }

    // Connect video appsink callback
    if (m_videoAppsink) {
        m_videoAppsinkSignalId = g_signal_connect(m_videoAppsink, "new-sample",
                                                   G_CALLBACK(OnNewVideoSample), this);
        m_hasVideoStream = true;
        LogDebug(VB_MEDIAOUT, "GStreamer video appsink connected\n");
    } else {
        m_videoAppsinkSignalId = 0;
    }

    // Clear the sample buffer for fresh playback
    {
        std::lock_guard<std::mutex> lock(s_sampleMutex);
        s_sampleBuffer.fill(0.0f);
        s_sampleWritePos = 0;
        s_sampleRate = 0;
    }

    // Apply volume adjustment if set
    if (m_volumeAdjust != 0 && m_volume) {
        // Convert dB adjustment to linear scale
        double linearVol = pow(10.0, m_volumeAdjust / 2000.0); // volAdj is in 0.01dB units
        g_object_set(m_volume, "volume", linearVol, NULL);
    }

    // Get the bus for message handling
    LogWarn(VB_MEDIAOUT, "GStreamer: Getting bus and setting sync handler...\n");
    m_bus = gst_element_get_bus(m_pipeline);

    // Install sync handler for autonomous bus message processing
    // This allows GStreamer playback to work without external Process() calls
    gst_bus_set_sync_handler(m_bus, BusSyncHandler, this, nullptr);

    // Flush AES67 send pipelines just before PLAYING so the drop probe
    // catches any PipeWire graph transition artifacts (combine-stream
    // gaining a new source).  The Close()-time flush is consumed by
    // silence buffers during idle, so this Start()-time flush is the
    // one that actually protects the AES67 receivers.
#ifdef HAS_AES67_GSTREAMER
    if (AES67Manager::INSTANCE.IsActive()) {
        AES67Manager::INSTANCE.FlushSendPipelines();
    }
#endif

    // Seek to start position if non-zero
    LogWarn(VB_MEDIAOUT, "GStreamer: Setting pipeline to PLAYING...\n");
    GstStateChangeReturn ret = gst_element_set_state(m_pipeline, GST_STATE_PLAYING);
    LogWarn(VB_MEDIAOUT, "GStreamer: set_state returned %d\n", ret);
    if (ret == GST_STATE_CHANGE_FAILURE) {
        LogErr(VB_MEDIAOUT, "Failed to set GStreamer pipeline to PLAYING\n");
        Close();
        return 0;
    }

    // If starting at a non-zero position, seek after state change
    if (msTime > 0) {
        gst_element_seek_simple(m_pipeline, GST_FORMAT_TIME,
                                (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_KEY_UNIT),
                                (gint64)msTime * GST_MSECOND);
    }

    m_playing = true;
    m_currentInstance = this;

#ifdef HAS_AES67_GSTREAMER
    // AES67 send pipelines run continuously (always sending to multicast).
    // No resume needed — filter-chain outputs silence when idle.
#endif

    if (m_mediaOutputStatus) {
        m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_PLAYING;
    }

    // Ensure channel output thread is running so ProcessVideoOverlay gets called
    // (same as SDLOutput behavior)
    if (m_videoOverlayModel) {
        StartChannelOutputThread();
    }

    Starting();
    LogInfo(VB_MEDIAOUT, "GStreamer started playing: %s\n", m_mediaFilename.c_str());
    return 1;
}

int GStreamerOutput::Stop(void) {
    LogDebug(VB_MEDIAOUT, "GStreamerOutput::Stop()\n");
    if (m_pipeline) {
        // Detach AES67 zero-hop RTP branches BEFORE pipeline goes NULL —
        // this resumes the standalone send pipeline so AES67 keeps working
        // between tracks.
#ifdef HAS_AES67_GSTREAMER
        DetachAES67Branches();
#endif
        Stopping();
        gst_element_set_state(m_pipeline, GST_STATE_NULL);
        m_playing = false;
        if (m_mediaOutputStatus) {
            m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
        }
        Stopped();
    }
    return 1;
}

int GStreamerOutput::Process(void) {
    if (!m_pipeline || !m_bus) {
        return 0;
    }
    ProcessMessages();

    // Update position
    if (m_playing) {
        gint64 pos = 0, dur = 0;
        bool havePos = gst_element_query_position(m_pipeline, GST_FORMAT_TIME, &pos);
        bool haveDur = gst_element_query_duration(m_pipeline, GST_FORMAT_TIME, &dur);

        if (havePos) {
            // Track the maximum observed duration — VBR MP3 files can report
            // fluctuating durations as GStreamer revises its estimate during
            // decoding.  Using the max prevents time-remaining from jumping
            // backwards or going negative.
            if (haveDur && dur > m_maxDuration) {
                m_maxDuration = dur;
            }
            gint64 effectiveDur = m_maxDuration;

            float elapsed = (float)pos / GST_SECOND;
            float remaining = (effectiveDur > pos) ? (float)(effectiveDur - pos) / GST_SECOND : 0.0f;
            setMediaElapsed(elapsed, remaining);

            // Always update total duration — it may be refined for VBR media
            if (effectiveDur > 0) {
                int totalSecs = (int)(effectiveDur / GST_SECOND);
                int newMin = totalSecs / 60;
                int newSec = totalSecs % 60;
                if (newMin != m_mediaOutputStatus->minutesTotal ||
                    newSec != m_mediaOutputStatus->secondsTotal) {
                    m_mediaOutputStatus->minutesTotal = newMin;
                    m_mediaOutputStatus->secondsTotal = newSec;
                    LogInfo(VB_MEDIAOUT, "GStreamer duration: %d:%02d\n", newMin, newSec);
                }
            }

            // Stall watchdog: detect when PipeWire stops consuming data
            // (e.g. HDMI sink unplugged causing combine-stream to block)
            // Skip watchdog near end of media — position naturally stops advancing
            bool nearEnd = (effectiveDur > 0 && (effectiveDur - pos) < GST_SECOND);

            if (pos != m_lastPosition) {
                m_lastPosition = pos;
                m_stallStartMs = 0; // position advancing, clear stall timer
            } else if (nearEnd) {
                // Position at/near end of media — this is natural completion, not a stall.
                // Wait for EOS from the pipeline, or handle it ourselves after a grace period.
                uint64_t now = GetTimeMS();
                if (m_stallStartMs == 0) {
                    m_stallStartMs = now;
                } else if ((now - m_stallStartMs) > (STALL_TIMEOUT_MS * 2)) {
                    // EOS should have arrived by now but didn't — force end
                    LogInfo(VB_MEDIAOUT, "GStreamer: media reached end (%.1fs/%.1fs), forcing stop\n",
                            elapsed, (float)effectiveDur / GST_SECOND);
                    m_playing = false;
                    if (m_mediaOutputStatus) {
                        m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
                    }
                    Stopping();
                    Stopped();
                    return 0;
                }
            } else {
                // Position unchanged — start or continue stall timer
                uint64_t now = GetTimeMS();
                if (m_stallStartMs == 0) {
                    m_stallStartMs = now;
                    LogDebug(VB_MEDIAOUT, "GStreamer: position stalled at %.1fs, starting watchdog\n", elapsed);
                } else if ((now - m_stallStartMs) > STALL_TIMEOUT_MS) {
                    LogWarn(VB_MEDIAOUT, "GStreamer pipeline stalled for %dms at position %.1fs — "
                            "audio sink may be blocked (HDMI unplugged?). Stopping playback.\n",
                            STALL_TIMEOUT_MS, elapsed);
                    m_playing = false;
                    if (m_mediaOutputStatus) {
                        m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
                    }
                    Stopping();
                    Stopped();
                    return 0;
                }
            }
        } else {
            LogExcess(VB_MEDIAOUT, "GStreamer position query pending (pipeline not yet PLAYING)\n");
        }
    }

    return m_playing ? 1 : 0;
}

void GStreamerOutput::ProcessMessages() {
    if (!m_bus)
        return;

    GstMessage* msg;
    while ((msg = gst_bus_pop(m_bus)) != nullptr) {
        switch (GST_MESSAGE_TYPE(msg)) {
        case GST_MESSAGE_EOS:
            LogDebug(VB_MEDIAOUT, "GStreamer: End of stream\n");
            if (m_loopCount > 0 || m_loopCount == -1) {
                // Loop: seek back to beginning
                if (m_loopCount > 0)
                    m_loopCount--;
                gst_element_seek_simple(m_pipeline, GST_FORMAT_TIME,
                                        (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_KEY_UNIT),
                                        0);
                LogDebug(VB_MEDIAOUT, "GStreamer: Looping (remaining: %d)\n", m_loopCount);
            } else {
                m_playing = false;
                if (m_mediaOutputStatus) {
                    m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
                }
                Stopping();
                Stopped();
            }
            break;

        case GST_MESSAGE_ERROR: {
            GError* err;
            gchar* debug;
            gst_message_parse_error(msg, &err, &debug);
            LogErr(VB_MEDIAOUT, "GStreamer error: %s\n", err->message);
            LogDebug(VB_MEDIAOUT, "GStreamer debug: %s\n", debug ? debug : "(none)");
            g_error_free(err);
            g_free(debug);
            m_playing = false;
            if (m_mediaOutputStatus) {
                m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
            }
            Stopping();
            Stopped();
            break;
        }

        case GST_MESSAGE_STATE_CHANGED: {
            if (GST_MESSAGE_SRC(msg) == GST_OBJECT(m_pipeline)) {
                GstState oldState, newState, pending;
                gst_message_parse_state_changed(msg, &oldState, &newState, &pending);
                LogDebug(VB_MEDIAOUT, "GStreamer state: %s -> %s\n",
                         gst_element_state_get_name(oldState),
                         gst_element_state_get_name(newState));
                if (newState == GST_STATE_PLAYING) {
                    Playing();
                }
            }
            break;
        }

        default:
            break;
        }
        gst_message_unref(msg);
    }
}

GstBusSyncReply GStreamerOutput::BusSyncHandler(GstBus* bus, GstMessage* msg, gpointer userData) {
    GStreamerOutput* self = static_cast<GStreamerOutput*>(userData);
    if (!self)
        return GST_BUS_PASS;

    switch (GST_MESSAGE_TYPE(msg)) {
    case GST_MESSAGE_EOS:
        LogInfo(VB_MEDIAOUT, "GStreamer sync: End of stream\n");
        if (self->m_loopCount > 0 || self->m_loopCount == -1) {
            if (self->m_loopCount > 0)
                self->m_loopCount--;
            gst_element_seek_simple(self->m_pipeline, GST_FORMAT_TIME,
                                    (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_KEY_UNIT),
                                    0);
            LogDebug(VB_MEDIAOUT, "GStreamer sync: Looping (remaining: %d)\n", self->m_loopCount);
        } else {
            // Detach AES67 inline branches so the standalone pipeline
            // resumes sending — EOS means no more data will flow through
            // the tee, so the inline branch is useless.
#ifdef HAS_AES67_GSTREAMER
            self->DetachAES67Branches();
#endif
            self->m_playing = false;
            if (self->m_mediaOutputStatus) {
                self->m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
            }
            self->Stopping();
            self->Stopped();
        }
        gst_message_unref(msg);
        return GST_BUS_DROP;

    case GST_MESSAGE_ERROR: {
        GError* err;
        gchar* debug;
        gst_message_parse_error(msg, &err, &debug);

        // Identify which element produced the error
        const gchar* srcName = GST_MESSAGE_SRC(msg) ?
            GST_OBJECT_NAME(GST_MESSAGE_SRC(msg)) : "unknown";

        // AES67 inline RTP branch elements are named "aes67_*".
        // Errors from these branches (e.g. network issues, PipeWire
        // disconnects) should NOT stop media playback.
        bool isAES67Branch = (strncmp(srcName, "aes67_", 6) == 0);

        if (isAES67Branch) {
            LogWarn(VB_MEDIAOUT, "GStreamer AES67 branch error (non-fatal, src=%s): %s\n",
                    srcName, err->message);
            LogDebug(VB_MEDIAOUT, "GStreamer AES67 branch debug: %s\n",
                     debug ? debug : "(none)");
        } else {
            LogErr(VB_MEDIAOUT, "GStreamer sync error (src=%s): %s\n", srcName, err->message);
            LogDebug(VB_MEDIAOUT, "GStreamer sync debug: %s\n", debug ? debug : "(none)");
#ifdef HAS_AES67_GSTREAMER
            self->DetachAES67Branches();
#endif
            self->m_playing = false;
            if (self->m_mediaOutputStatus) {
                self->m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
            }
            self->Stopping();
            self->Stopped();
        }

        g_error_free(err);
        g_free(debug);
        gst_message_unref(msg);
        return GST_BUS_DROP;
    }

    case GST_MESSAGE_STATE_CHANGED: {
        if (GST_MESSAGE_SRC(msg) == GST_OBJECT(self->m_pipeline)) {
            GstState oldState, newState, pending;
            gst_message_parse_state_changed(msg, &oldState, &newState, &pending);
            LogDebug(VB_MEDIAOUT, "GStreamer sync state: %s -> %s\n",
                     gst_element_state_get_name(oldState),
                     gst_element_state_get_name(newState));
            if (newState == GST_STATE_PLAYING) {
                self->Playing();
            }
        }
        // Let state changes pass through for normal GStreamer operation
        return GST_BUS_PASS;
    }

    default:
        return GST_BUS_PASS;
    }
}

int GStreamerOutput::Close(void) {
    LogDebug(VB_MEDIAOUT, "GStreamerOutput::Close()\n");
    if (m_pipeline) {
        // Detach AES67 zero-hop RTP branches before pipeline teardown
#ifdef HAS_AES67_GSTREAMER
        DetachAES67Branches();
        // AES67 send pipelines run continuously — no pause needed.
#endif

        // Set shutdown flag FIRST — this prevents OnNewSample/OnNewVideoSample from doing any work
        m_shutdownFlag.store(true);

        // Flush PipeWire filter-chain delay buffers.  Each audio group member
        // has a builtin delay node whose internal ring-buffer retains old
        // audio.  Setting the delay to 0 empties it; restoring the original
        // value afterwards starts accumulating from scratch with silence.
        // Spawned as a detached thread so we don't block Close().
        FlushPipeWireDelayBuffers();

        // Flush AES67 send pipelines — drop a few buffers to discard
        // tail-end audio from the ending track.  The Start()-time flush
        // is the primary protection; this Close() flush is supplementary
        // for cases where Close→Start is very fast (back-to-back tracks).
#ifdef HAS_AES67_GSTREAMER
        if (AES67Manager::INSTANCE.IsActive()) {
            AES67Manager::INSTANCE.FlushSendPipelines();
        }
#endif

        // Disconnect appsink signals and disable emission BEFORE pipeline state change.
        // This prevents streaming threads from calling callbacks during teardown,
        // which can deadlock with gst_element_set_state(NULL) due to malloc arena locks.
        if (m_appsink) {
            if (m_appsinkSignalId > 0) {
                g_signal_handler_disconnect(m_appsink, m_appsinkSignalId);
                m_appsinkSignalId = 0;
            }
            g_object_set(m_appsink, "emit-signals", FALSE, NULL);
        }
        if (m_videoAppsink) {
            if (m_videoAppsinkSignalId > 0) {
                g_signal_handler_disconnect(m_videoAppsink, m_videoAppsinkSignalId);
                m_videoAppsinkSignalId = 0;
            }
            g_object_set(m_videoAppsink, "emit-signals", FALSE, NULL);
        }

        // Remove bus sync handler before state change
        if (m_bus) {
            gst_bus_set_sync_handler(m_bus, nullptr, nullptr, nullptr);
        }
        Stop();

        // Restore overlay model state if we enabled it
        if (m_wasOverlayDisabled) {
            std::lock_guard<std::mutex> lock(m_videoOverlayModelLock);
            if (m_videoOverlayModel) {
                m_videoOverlayModel->setState(PixelOverlayState(PixelOverlayState::Disabled));
            }
            m_wasOverlayDisabled = false;
        }

        if (m_appsink) {
            gst_object_unref(m_appsink);
            m_appsink = nullptr;
        }
        if (m_videoAppsink) {
            gst_object_unref(m_videoAppsink);
            m_videoAppsink = nullptr;
        }
        if (m_volume) {
            gst_object_unref(m_volume);
            m_volume = nullptr;
        }
        if (m_bus) {
            gst_object_unref(m_bus);
            m_bus = nullptr;
        }
        gst_object_unref(m_pipeline);
        m_pipeline = nullptr;
    }

    // Clean up video overlay state
    if (m_videoFramesReceived > 0 || m_videoFramesDelivered > 0) {
        LogInfo(VB_MEDIAOUT, "GStreamer video overlay stats: %lu frames received, %lu delivered\n",
                (unsigned long)m_videoFramesReceived, (unsigned long)m_videoFramesDelivered);
    }
    m_hasVideoStream = false;
    m_videoFrameReady = false;
    m_videoFramesReceived = 0;
    m_videoFramesDelivered = 0;
    m_audioChain = nullptr;
    m_videoChain = nullptr;
    m_kmssink = nullptr;     // owned by pipeline bin, already freed
    m_wantHDMI = false;

    // Remove model listener
    if (!m_videoOut.empty() && m_videoOut != "--Disabled--") {
        PixelOverlayManager::INSTANCE.removeModelListener(m_videoOut, "GStreamerOut");
    }
    {
        std::lock_guard<std::mutex> lock(m_videoOverlayModelLock);
        m_videoOverlayModel = nullptr;
    }

    if (m_currentInstance == this) {
        m_currentInstance = nullptr;
    }
    return 1;
}

int GStreamerOutput::IsPlaying(void) {
    return m_playing ? 1 : 0;
}

int GStreamerOutput::AdjustSpeed(float masterMediaPosition) {
    if (!m_pipeline || !m_allowSpeedAdjust)
        return 1;

    // Can't adjust speed if not playing yet
    if (m_mediaOutputStatus->mediaSeconds < 0.01f) {
        LogDebug(VB_MEDIAOUT, "GStreamer: Can't adjust speed if not playing yet (%0.3f/%0.3f)\n",
                 masterMediaPosition, m_mediaOutputStatus->mediaSeconds);
        return 1;
    }
    if (m_mediaOutputStatus->mediaSeconds > 1 && m_mediaOutputStatus->status == MEDIAOUTPUTSTATUS_IDLE) {
        LogDebug(VB_MEDIAOUT, "GStreamer: Can't adjust speed if beyond end of media (%0.3f/%0.3f)\n",
                 masterMediaPosition, m_mediaOutputStatus->mediaSeconds);
        return 1;
    }

    float rate = m_currentRate;

    if (m_lastRates.empty()) {
        // Preload rate list with normal (1.0) rate
        m_lastRates.push_back(1.0f);
        m_lastRatesSum = 1.0f;
    }

    int rawdiff = (int)(m_mediaOutputStatus->mediaSeconds * 1000) - (int)(masterMediaPosition * 1000);
    int diff = rawdiff;
    int sign = 1;
    if (diff < 0) {
        sign = -1;
        diff = -diff;
    }

    if ((m_mediaOutputStatus->mediaSeconds < 1) || (diff > 3000)) {
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tMaster: %0.3f  Local: %0.3f  Rate: %0.3f\n",
                 rawdiff, masterMediaPosition, m_mediaOutputStatus->mediaSeconds, m_currentRate);
    } else {
        LogExcess(VB_MEDIAOUT, "GStreamer Diff: %d\tMaster: %0.3f  Local: %0.3f  Rate: %0.3f\n",
                  rawdiff, masterMediaPosition, m_mediaOutputStatus->mediaSeconds, m_currentRate);
    }

    pushDiff(rawdiff, m_currentRate);

    // Sign-flip detection: if diff sign flipped and we're not at normal speed,
    // reset to 1.0 to avoid oscillation
    int oldSign = m_lastDiff < 0 ? -1 : 1;
    if ((oldSign != sign) && (m_lastDiff != 0) && (m_currentRate != 1.0f)) {
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tFlipped, reset speed to normal\t(%0.3f)\n", rawdiff, 1.0f);
        ApplyRate(1.0f);
        // Reset rate average list to 1.0
        m_lastRates.clear();
        m_lastRates.push_back(1.0f);
        m_lastRatesSum = 1.0f;
        m_currentRate = 1.0f;
        m_rateDiff = 0;
        m_lastDiff = rawdiff;
        return 1;
    }

    if (diff < 30) {
        // Close enough — return to normal rate
        if (m_currentRate != 1.0f) {
            rate = 1.0f;
            LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tVery close, use normal rate\t(%0.3f)\n", rawdiff, rate);
            ApplyRate(rate);
            m_lastRates.push_back(rate);
            m_lastRatesSum += rate;
            while ((int)m_lastRates.size() > RATE_AVERAGE_COUNT) {
                m_lastRatesSum -= m_lastRates.front();
                m_lastRates.pop_front();
            }
            m_currentRate = rate;
            m_rateDiff = 0;
            m_lastDiff = rawdiff;
        }
        return 1;
    } else if (diff > 10000) {
        // More than 10 seconds off — jump to the master position
        gint64 pos_ns = (gint64)(masterMediaPosition * GST_SECOND);
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tVery far, jumping to: %0.3f\t(currently at %0.3f)\n",
                 rawdiff, masterMediaPosition, m_mediaOutputStatus->mediaSeconds);
        gst_element_seek(m_pipeline, 1.0, GST_FORMAT_TIME,
                         (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_ACCURATE),
                         GST_SEEK_TYPE_SET, pos_ns, GST_SEEK_TYPE_NONE, 0);
        m_lastRates.clear();
        m_lastRates.push_back(1.0f);
        m_lastRatesSum = 1.0f;
        m_currentRate = 1.0f;
        m_rateDiff = 0;
        m_lastDiff = -1; // after seeking, assume slightly behind master
        return 1;
    } else if (diff < 100) {
        // Very close — could be transient; delay one cycle
        if (!m_lastDiff) {
            LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tVery close but could be transient, wait till next time\n", rawdiff);
            m_lastDiff = rawdiff;
            return 1;
        }
    }

    // Calculate proportional rate adjustment
    float rateDiffF = (float)diff;
    if (m_mediaOutputStatus->mediaSeconds > 10) {
        rateDiffF /= 100.0f;
        if (rateDiffF > 10.0f)
            rateDiffF = 10.0f;
    } else {
        // In first 10 seconds, use larger rate changes to sync faster
        rateDiffF /= 50.0f;
        if (rateDiffF > 20.0f)
            rateDiffF = 20.0f;
    }

    rateDiffF *= sign;
    int rateDiffI = (int)std::round(rateDiffF);

    LogExcess(VB_MEDIAOUT, "GStreamer Diff: %d\trateDiffI: %d  m_rateDiff: %d\n", rawdiff, rateDiffI, m_rateDiff);

    if (rateDiffI < m_rateDiff) {
        for (int r = rateDiffI; r < m_rateDiff; r++) {
            rate = rate * 1.02f;
        }
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tSpeedUp  %0.3f/%0.3f [goal/current]\n", rawdiff, rate, m_currentRate);
    } else if (rateDiffI > m_rateDiff) {
        for (int r = rateDiffI; r > m_rateDiff; r--) {
            rate = rate * 0.98f;
        }
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tSlowDown %0.3f/%0.3f [goal/current]\n", rawdiff, rate, m_currentRate);
    } else {
        // No rate change needed
        LogExcess(VB_MEDIAOUT, "GStreamer Diff: %d\tno rate change\n", rawdiff);
        return 1;
    }

    // Add to rate history for running average
    m_lastRates.push_back(rate);
    m_lastRatesSum += rate;
    if ((int)m_lastRates.size() > RATE_AVERAGE_COUNT) {
        m_lastRatesSum -= m_lastRates.front();
        m_lastRates.pop_front();
    }

    // Cross-unity check: if rate crossed 1.0, reset to 1.0
    if (((rate > 1.0f) && (m_currentRate < 1.0f)) || ((rate < 1.0f) && (m_currentRate > 1.0f))) {
        rate = 1.0f;
        m_rateDiff = 0;
    }

    LogExcess(VB_MEDIAOUT, "GStreamer Diff: %d\toldDiff: %d\tnewRate: %0.3f oldRate: %0.3f avgRate: %0.3f rateSum: %0.3f/%d\n",
              rawdiff, m_lastDiff, m_lastRates.back(), m_currentRate, rate, m_lastRatesSum, (int)m_lastRates.size());

    // Clamp rate to safe range
    if (rate > 2.0f)
        rate = 2.0f;
    if (rate < 0.5f)
        rate = 0.5f;

    // Only apply if rate changed by > 0.001
    if ((int)(rate * 1000) != (int)(m_currentRate * 1000)) {
        LogDebug(VB_MEDIAOUT, "GStreamer Diff: %d\tApplyRate\t(%0.3f)\n", rawdiff, rate);
        ApplyRate(rate);
        m_currentRate = rate;
        if (rate == 1.0f) {
            m_rateDiff = 0;
        } else {
            m_rateDiff = rateDiffI;
        }
    }

    m_lastDiff = rawdiff;
    return 1;
}

void GStreamerOutput::pushDiff(int diff, float rate) {
    m_diffSum += diff;
    m_rateSum += rate;
    if (m_diffsSize < MAX_DIFFS) {
        m_diffIdx = m_diffsSize;
        m_diffsSize++;
    } else {
        m_diffIdx++;
        if (m_diffIdx == MAX_DIFFS) {
            m_diffIdx = 0;
        }
        m_diffSum -= m_diffs[m_diffIdx].first;
        m_rateSum -= m_diffs[m_diffIdx].second;
    }
    m_diffs[m_diffIdx].first = diff;
    m_diffs[m_diffIdx].second = rate;
}

void GStreamerOutput::ApplyRate(float rate) {
    if (!m_pipeline)
        return;

    // Use instant-rate-change (GStreamer >= 1.18) for glitch-free rate adjustment.
    // Falls back to a flush seek if instant-rate-change fails.
    gboolean ok = gst_element_seek(m_pipeline, (gdouble)rate, GST_FORMAT_TIME,
                                   GST_SEEK_FLAG_INSTANT_RATE_CHANGE,
                                   GST_SEEK_TYPE_NONE, 0, GST_SEEK_TYPE_NONE, 0);
    if (!ok) {
        // Fallback: flush seek to current position with new rate
        gint64 pos = 0;
        if (gst_element_query_position(m_pipeline, GST_FORMAT_TIME, &pos)) {
            LogDebug(VB_MEDIAOUT, "GStreamer: instant-rate-change failed, falling back to flush seek at %" GST_TIME_FORMAT "\n",
                     GST_TIME_ARGS(pos));
            gst_element_seek(m_pipeline, (gdouble)rate, GST_FORMAT_TIME,
                             (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_KEY_UNIT),
                             GST_SEEK_TYPE_SET, pos, GST_SEEK_TYPE_NONE, 0);
        } else {
            LogWarn(VB_MEDIAOUT, "GStreamer: ApplyRate(%0.3f) failed — could not query position\n", rate);
        }
    }
}

void GStreamerOutput::SetVolume(int volume) {
    if (m_volume) {
        // volume parameter is 0-100 percentage
        double linearVol = volume / 100.0;
        g_object_set(m_volume, "volume", linearVol, NULL);
        LogDebug(VB_MEDIAOUT, "GStreamer volume set to %d%% (%.2f)\n", volume, linearVol);
    }
}

void GStreamerOutput::SetVolumeAdjustment(int volAdj) {
    m_volumeAdjust = volAdj;
    if (m_volume && m_playing) {
        double linearVol = pow(10.0, volAdj / 2000.0);
        g_object_set(m_volume, "volume", linearVol, NULL);
    }
}

// Static video overlay methods — called from Sequence.cpp and channeloutputthread.cpp
bool GStreamerOutput::IsOverlayingVideo() {
    return m_currentInstance && m_currentInstance->m_hasVideoStream &&
           m_currentInstance->m_playing && m_currentInstance->m_videoOverlayModel;
}

bool GStreamerOutput::ProcessVideoOverlay(unsigned int msTimestamp) {
    if (!m_currentInstance || !m_currentInstance->m_playing || !m_currentInstance->m_hasVideoStream)
        return false;

    GStreamerOutput* self = m_currentInstance;

    // Copy the latest video frame data under lock
    std::vector<uint8_t> frameData;
    {
        std::lock_guard<std::mutex> lock(self->m_videoFrameMutex);
        if (!self->m_videoFrameReady)
            return false;
        frameData = self->m_videoFrameData;
        self->m_videoFrameReady = false;
    }

    // Push RGB data to the PixelOverlayModel
    std::lock_guard<std::mutex> lock(self->m_videoOverlayModelLock);
    if (self->m_videoOverlayModel && !frameData.empty()) {
        self->m_videoOverlayModel->setData(frameData.data());
        self->m_videoFramesDelivered++;

        if (self->m_videoFramesDelivered == 1 || (self->m_videoFramesDelivered % 100) == 0) {
            LogInfo(VB_MEDIAOUT, "GStreamer: video frame %lu delivered to overlay (%zu bytes)\n",
                    (unsigned long)self->m_videoFramesDelivered, frameData.size());
        }

        // Auto-enable model if it was disabled (same as SDLOutput behavior)
        if (self->m_videoOverlayModel->getState() == PixelOverlayState::Disabled) {
            self->m_wasOverlayDisabled = true;
            self->m_videoOverlayModel->setState(PixelOverlayState(PixelOverlayState::Enabled));
        }
    }
    return false;
}

GstFlowReturn GStreamerOutput::OnNewVideoSample(GstAppSink* appsink, gpointer userData) {
    GStreamerOutput* self = static_cast<GStreamerOutput*>(userData);
    if (!self || self->m_shutdownFlag.load(std::memory_order_acquire))
        return GST_FLOW_EOS;

    GstSample* sample = gst_app_sink_pull_sample(appsink);
    if (!sample)
        return GST_FLOW_OK;

    GstBuffer* buffer = gst_sample_get_buffer(sample);
    GstMapInfo map;
    if (gst_buffer_map(buffer, &map, GST_MAP_READ)) {
        int width = self->m_videoOverlayWidth;
        int height = self->m_videoOverlayHeight;
        int rowBytes = width * 3;  // RGB = 3 bytes/pixel, tightly packed
        int expectedSize = rowBytes * height;
        int stride = (height > 0) ? (int)(map.size / height) : rowBytes;

        std::lock_guard<std::mutex> lock(self->m_videoFrameMutex);
        if (stride == rowBytes) {
            // No padding — direct copy
            self->m_videoFrameData.assign(map.data, map.data + expectedSize);
        } else {
            // GStreamer pads rows to 4-byte alignment — strip padding
            self->m_videoFrameData.resize(expectedSize);
            for (int y = 0; y < height; y++) {
                memcpy(&self->m_videoFrameData[y * rowBytes], map.data + y * stride, rowBytes);
            }
        }
        self->m_videoFrameReady = true;
        self->m_videoFramesReceived++;
        if (self->m_videoFramesReceived == 1 || (self->m_videoFramesReceived % 100) == 0) {
            LogInfo(VB_MEDIAOUT, "GStreamer: video frame %lu received (%zu bytes, stride=%d, rowBytes=%d)\n",
                    (unsigned long)self->m_videoFramesReceived, map.size, stride, rowBytes);
        }
        gst_buffer_unmap(buffer, &map);
    }

    gst_sample_unref(sample);
    return GST_FLOW_OK;
}

void GStreamerOutput::OnPadAdded(GstElement* element, GstPad* pad, gpointer userData) {
    GStreamerOutput* self = static_cast<GStreamerOutput*>(userData);
    if (!self)
        return;

    GstCaps* caps = gst_pad_get_current_caps(pad);
    if (!caps)
        caps = gst_pad_query_caps(pad, nullptr);

    if (!caps)
        return;

    // Log all structures in the caps for debugging
    for (guint i = 0; i < gst_caps_get_size(caps); i++) {
        const gchar* sname = gst_structure_get_name(gst_caps_get_structure(caps, i));
        LogDebug(VB_MEDIAOUT, "GStreamer decodebin pad-added caps[%u]: %s\n", i, sname);
    }

    const gchar* name = gst_structure_get_name(gst_caps_get_structure(caps, 0));
    LogInfo(VB_MEDIAOUT, "GStreamer decodebin pad-added: %s\n", name);

    if (g_str_has_prefix(name, "audio/") && self->m_audioChain) {
        GstPad* sinkPad = gst_element_get_static_pad(self->m_audioChain, "sink");
        if (sinkPad && !gst_pad_is_linked(sinkPad)) {
            GstPadLinkReturn ret = gst_pad_link(pad, sinkPad);
            if (GST_PAD_LINK_FAILED(ret)) {
                LogErr(VB_MEDIAOUT, "GStreamer: Failed to link audio pad: %d\n", ret);
            } else {
                LogInfo(VB_MEDIAOUT, "GStreamer: Linked audio pad successfully\n");
                self->m_audioLinked = true;
            }
        } else {
            LogWarn(VB_MEDIAOUT, "GStreamer: Audio pad already linked or sink pad unavailable\n");
        }
        if (sinkPad)
            gst_object_unref(sinkPad);
    } else if (g_str_has_prefix(name, "video/") && self->m_videoChain) {
        GstPad* sinkPad = gst_element_get_static_pad(self->m_videoChain, "sink");
        if (sinkPad && !gst_pad_is_linked(sinkPad)) {
            GstPadLinkReturn ret = gst_pad_link(pad, sinkPad);
            if (GST_PAD_LINK_FAILED(ret)) {
                LogErr(VB_MEDIAOUT, "GStreamer: Failed to link video pad: %d\n", ret);
            } else {
                LogInfo(VB_MEDIAOUT, "GStreamer: Linked video pad successfully\n");
                self->m_videoLinked = true;
            }
        } else {
            LogWarn(VB_MEDIAOUT, "GStreamer: Video pad already linked or sink pad unavailable\n");
        }
        if (sinkPad)
            gst_object_unref(sinkPad);
    } else {
        LogDebug(VB_MEDIAOUT, "GStreamer: Ignoring pad with caps: %s\n", name);
    }

    gst_caps_unref(caps);
}

void GStreamerOutput::OnNoMorePads(GstElement* element, gpointer userData) {
    GStreamerOutput* self = static_cast<GStreamerOutput*>(userData);
    if (!self)
        return;

    LogInfo(VB_MEDIAOUT, "GStreamer: no-more-pads (audio=%s, video=%s)\n",
            self->m_audioLinked ? "linked" : "not linked",
            self->m_videoLinked ? "linked" : "not linked");

    // If audio chain was never connected, remove orphaned audio elements from the bin.
    // Without this, the pipeline will never reach EOS because the audio sinks
    // never receive data and never post their individual EOS events.
    if (!self->m_audioLinked && self->m_audioChain && self->m_pipeline) {
        LogInfo(VB_MEDIAOUT, "GStreamer: Removing unconnected audio chain (video-only media)\n");

        // Get all audio elements by name and remove them
        const char* audioNames[] = {"aconv", "aresample", "t", "q1", "vol", "pwsink", "audiosink",
                                    "q2", "aconv2", "acapsf", "sampletap", nullptr};
        for (int i = 0; audioNames[i]; i++) {
            GstElement* el = gst_bin_get_by_name(GST_BIN(self->m_pipeline), audioNames[i]);
            if (el) {
                gst_element_set_state(el, GST_STATE_NULL);
                gst_bin_remove(GST_BIN(self->m_pipeline), el);
                gst_object_unref(el);
            }
        }

        // Release our refs and clear audio pointers
        if (self->m_appsink) {
            gst_object_unref(self->m_appsink);
            self->m_appsink = nullptr;
        }
        if (self->m_volume) {
            gst_object_unref(self->m_volume);
            self->m_volume = nullptr;
        }
        self->m_audioChain = nullptr;
        self->m_appsinkSignalId = 0;
    }

    // If video chain was never connected, remove orphaned video elements.
    if (!self->m_videoLinked && self->m_videoChain && self->m_pipeline) {
        LogInfo(VB_MEDIAOUT, "GStreamer: Removing unconnected video chain (audio-only media)\n");

        const char* videoNames[] = {"vq", "vconv", "vscale", "vcapsf", "videosink", "kmsvideosink", nullptr};
        for (int i = 0; videoNames[i]; i++) {
            GstElement* el = gst_bin_get_by_name(GST_BIN(self->m_pipeline), videoNames[i]);
            if (el) {
                gst_element_set_state(el, GST_STATE_NULL);
                gst_bin_remove(GST_BIN(self->m_pipeline), el);
                gst_object_unref(el);
            }
        }

        // Release our ref and clear video pointers
        if (self->m_videoAppsink) {
            gst_object_unref(self->m_videoAppsink);
            self->m_videoAppsink = nullptr;
        }
        self->m_videoChain = nullptr;
        self->m_videoAppsinkSignalId = 0;
        self->m_hasVideoStream = false;
        self->m_kmssink = nullptr;  // owned by pipeline bin, already removed above
    }
}

GstFlowReturn GStreamerOutput::OnNewSample(GstAppSink* appsink, gpointer userData) {
    GStreamerOutput* self = static_cast<GStreamerOutput*>(userData);
    if (!self || self->m_shutdownFlag.load(std::memory_order_acquire))
        return GST_FLOW_EOS;

    GstSample* sample = gst_app_sink_pull_sample(appsink);
    if (!sample)
        return GST_FLOW_OK;

    GstBuffer* buffer = gst_sample_get_buffer(sample);
    GstCaps* caps = gst_sample_get_caps(sample);

    // Extract sample rate from caps on first buffer
    if (caps) {
        GstStructure* s = gst_caps_get_structure(caps, 0);
        int rate = 0;
        if (gst_structure_get_int(s, "rate", &rate) && rate > 0) {
            std::lock_guard<std::mutex> lock(s_sampleMutex);
            s_sampleRate = rate;
        }
    }

    GstMapInfo map;
    if (gst_buffer_map(buffer, &map, GST_MAP_READ)) {
        int numFloats = map.size / sizeof(float);
        const float* src = reinterpret_cast<const float*>(map.data);

        std::lock_guard<std::mutex> lock(s_sampleMutex);
        for (int i = 0; i < numFloats; i++) {
            s_sampleBuffer[s_sampleWritePos] = src[i];
            s_sampleWritePos = (s_sampleWritePos + 1) % SAMPLE_BUFFER_SIZE;
        }

        gst_buffer_unmap(buffer, &map);
    }

    gst_sample_unref(sample);
    return GST_FLOW_OK;
}

bool GStreamerOutput::GetAudioSamples(float* samples, int numSamples, int& sampleRate) {
    if (!m_currentInstance || !m_currentInstance->m_playing)
        return false;

    std::lock_guard<std::mutex> lock(s_sampleMutex);
    if (s_sampleRate == 0)
        return false;

    sampleRate = s_sampleRate;

    // Read the most recent numSamples from the circular buffer
    int readPos = (s_sampleWritePos - numSamples + SAMPLE_BUFFER_SIZE) % SAMPLE_BUFFER_SIZE;
    for (int i = 0; i < numSamples; i++) {
        samples[i] = s_sampleBuffer[readPos];
        readPos = (readPos + 1) % SAMPLE_BUFFER_SIZE;
    }
    return true;
}

// ──────────────────────────────────────────────────────────────────────────────
// AES67 zero-hop RTP branch helpers (Phase 7.9)
// ──────────────────────────────────────────────────────────────────────────────
#ifdef HAS_AES67_GSTREAMER
void GStreamerOutput::AttachAES67Branches(GstElement* tee) {
    // Inline zero-hop branches are disabled.  They create a second RTP stream
    // (different SSRC) alongside the standalone pipewiresrc→udpsink pipeline,
    // which confuses AES67 receivers causing repeated/out-of-time audio.
    // The standalone pipeline with sync=false already provides low latency.
    (void)tee;
    return;
}

void GStreamerOutput::DetachAES67Branches() {
    // No-op: inline branches are disabled.
}
#endif // HAS_AES67_GSTREAMER

// ──────────────────────────────────────────────────────────────────────────────
// PipeWire filter-chain delay buffer flush
// ──────────────────────────────────────────────────────────────────────────────
// When media stops, PipeWire's builtin delay nodes retain old audio in their
// ring-buffers.  If the next song starts before that audio drains naturally,
// the listener hears a burst of the previous track.  This function resets all
// delay controls to 0 (clearing the ring-buffers) and then immediately
// restores the original values so they begin accumulating from silence.
void GStreamerOutput::FlushPipeWireDelayBuffers() {
    // Read audio groups config to find delay values
    std::string configPath = FPP_DIR_CONFIG("/pipewire-audio-groups.json");
    if (!FileExists(configPath)) {
        return;
    }

    Json::Value root;
    if (!LoadJsonFromFile(configPath, root) || !root.isMember("groups")) {
        return;
    }

    // Channel labels matching the PHP filter-chain generator
    static const char* channelLabels[] = { "l", "r", "c", "lfe", "rl", "rr", "sl", "sr" };

    struct DelayInfo {
        std::string fxNodeName; // e.g. fpp_fx_g1_s3
        int channels;
        double delaySec;
    };
    std::vector<DelayInfo> delays;

    for (const auto& group : root["groups"]) {
        int groupId = group.get("id", 0).asInt();
        if (!group.isMember("members"))
            continue;
        for (const auto& member : group["members"]) {
            std::string cardId = member.get("cardId", "").asString();
            double delayMs = member.get("delayMs", 0.0).asDouble();
            int channels = member.get("channels", 2).asInt();
            if (cardId.empty() || delayMs <= 0)
                continue; // no delay buffer to flush

            // Normalize card ID to match PHP: preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cardId))
            std::string cardIdNorm;
            for (char c : cardId) {
                if (std::isalnum(c) || c == '_')
                    cardIdNorm += std::tolower(c);
                else
                    cardIdNorm += '_';
            }
            std::string fxNodeName = "fpp_fx_g" + std::to_string(groupId) + "_" + cardIdNorm;
            delays.push_back({fxNodeName, std::min(channels, 8), delayMs / 1000.0});
        }
    }

    if (delays.empty())
        return;

    // Fire-and-forget thread: set delays to 0, wait a quantum, restore.
    std::thread([delays]() {
        const char* env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";

        // Phase 1: set all delays to 0 (clears ring-buffers)
        for (const auto& d : delays) {
            // Find node ID
            std::string findCmd = std::string(env) + " pw-cli ls Node 2>/dev/null | grep -B1 'node.name = \"" + d.fxNodeName + "\"' | head -1 | awk '{print $2}'";
            FILE* fp = popen(findCmd.c_str(), "r");
            if (!fp) continue;
            char buf[64] = {};
            if (!fgets(buf, sizeof(buf), fp)) { pclose(fp); continue; }
            pclose(fp);
            int nodeId = atoi(buf);
            if (nodeId <= 0) continue;

            // Build params string: "delay_l:Delay (s)" 0 "delay_r:Delay (s)" 0 ...
            std::string params;
            for (int ch = 0; ch < d.channels; ch++) {
                if (!params.empty()) params += " ";
                params += "\"delay_";
                params += channelLabels[ch];
                params += ":Delay (s)\" 0";
            }
            std::string cmd = std::string(env) + " pw-cli set-param " + std::to_string(nodeId)
                + " Props '{ params = [ " + params + " ] }' 2>/dev/null";
            system(cmd.c_str());
        }

        // Wait two PipeWire quanta (~42ms) for the zero-delay to take effect
        std::this_thread::sleep_for(std::chrono::milliseconds(50));

        // Phase 2: restore original delay values
        for (const auto& d : delays) {
            std::string findCmd = std::string(env) + " pw-cli ls Node 2>/dev/null | grep -B1 'node.name = \"" + d.fxNodeName + "\"' | head -1 | awk '{print $2}'";
            FILE* fp = popen(findCmd.c_str(), "r");
            if (!fp) continue;
            char buf[64] = {};
            if (!fgets(buf, sizeof(buf), fp)) { pclose(fp); continue; }
            pclose(fp);
            int nodeId = atoi(buf);
            if (nodeId <= 0) continue;

            std::string params;
            for (int ch = 0; ch < d.channels; ch++) {
                if (!params.empty()) params += " ";
                params += "\"delay_";
                params += channelLabels[ch];
                params += ":Delay (s)\" ";
                params += std::to_string(d.delaySec);
            }
            std::string cmd = std::string(env) + " pw-cli set-param " + std::to_string(nodeId)
                + " Props '{ params = [ " + params + " ] }' 2>/dev/null";
            system(cmd.c_str());
        }

        LogDebug(VB_MEDIAOUT, "PipeWire delay buffers flushed and restored\n");
    }).detach();
}

#endif // HAS_GSTREAMER
