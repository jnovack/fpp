/*
 * This file is part of the Falcon Player (FPP) and is Copyright (C)
 * 2013-2026 by the Falcon Player Developers.
 *
 * The Falcon Player (FPP) is free software, and is covered under
 * multiple Open Source licenses.  Please see the included 'LICENSES'
 * file for descriptions of what files are covered by each license.
 *
 * This source file is covered under the LGPL v2.1 as described in the
 * included LICENSE.LGPL file.
 */

#include "fpp-pch.h"

#include "VideoInputManager.h"
#include "common.h"
#include "settings.h"
#include "log.h"

#include <fstream>

#if __has_include(<jsoncpp/json/json.h>)
#include <jsoncpp/json/json.h>
#elif __has_include(<json/json.h>)
#include <json/json.h>
#endif

// pipewiresink mode=provide enum value (GST_PIPEWIRE_SINK_MODE_PROVIDE)
static constexpr int PIPEWIRE_SINK_MODE_PROVIDE = 2;

VideoInputManager& VideoInputManager::Instance() {
    static VideoInputManager instance;
    return instance;
}

VideoInputManager::~VideoInputManager() {
    Shutdown();
}

void VideoInputManager::Init() {
    std::lock_guard<std::mutex> lock(m_mutex);
    if (m_initialized)
        return;

    // Only init if PipeWire backend is active
    std::string backend = getSetting("AudioBackend");
    if (backend != "pipewire") {
        LogDebug(VB_MEDIAOUT, "VideoInputManager: Skipping init (AudioBackend=%s, not pipewire)\n", backend.c_str());
        return;
    }

    m_initialized = true;

    if (LoadConfig()) {
        LogInfo(VB_MEDIAOUT, "VideoInputManager: Loaded %d source(s), starting enabled ones\n",
                (int)m_sources.size());
        for (auto& source : m_sources) {
            if (source.enabled) {
                StartSource(source);
            }
        }
    } else {
        LogDebug(VB_MEDIAOUT, "VideoInputManager: No video input sources configured\n");
    }
}

void VideoInputManager::Reload() {
    LogInfo(VB_MEDIAOUT, "VideoInputManager: Reloading configuration\n");

    {
        std::lock_guard<std::mutex> lock(m_mutex);
        StopAllSources();
        m_sources.clear();
    }

    std::lock_guard<std::mutex> lock(m_mutex);
    m_initialized = true;

    if (LoadConfig()) {
        LogInfo(VB_MEDIAOUT, "VideoInputManager: Reloaded with %d source(s)\n", (int)m_sources.size());
        for (auto& source : m_sources) {
            if (source.enabled) {
                StartSource(source);
            }
        }
    }
}

void VideoInputManager::Shutdown() {
    std::lock_guard<std::mutex> lock(m_mutex);
    StopAllSources();
    m_sources.clear();
    m_initialized = false;
    LogInfo(VB_MEDIAOUT, "VideoInputManager: Shutdown complete\n");
}

bool VideoInputManager::HasSources() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    return !m_sources.empty();
}

bool VideoInputManager::HasActiveSources() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    for (const auto& s : m_sources) {
        if (s.running)
            return true;
    }
    return false;
}

int VideoInputManager::ActiveSourceCount() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    int count = 0;
    for (const auto& s : m_sources) {
        if (s.running)
            count++;
    }
    return count;
}

std::string VideoInputManager::GetSourceNodeName(int sourceId) const {
    std::lock_guard<std::mutex> lock(m_mutex);
    for (const auto& s : m_sources) {
        if (s.id == sourceId && s.running)
            return s.pipeWireNodeName;
    }
    return "";
}

std::vector<std::pair<int, std::string>> VideoInputManager::GetSourceList() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    std::vector<std::pair<int, std::string>> result;
    for (const auto& s : m_sources) {
        result.emplace_back(s.id, s.pipeWireNodeName);
    }
    return result;
}

bool VideoInputManager::LoadConfig() {
    std::string configPath = FPP_DIR_MEDIA("/config/pipewire-video-input-sources-gen.json");

    if (!FileExists(configPath)) {
        LogDebug(VB_MEDIAOUT, "VideoInputManager: No config at %s\n", configPath.c_str());
        return false;
    }

    std::ifstream ifs(configPath);
    if (!ifs.is_open()) {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: Cannot open %s\n", configPath.c_str());
        return false;
    }

    Json::Value root;
    Json::CharReaderBuilder builder;
    std::string errors;
    if (!Json::parseFromStream(builder, ifs, &root, &errors)) {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: JSON parse error: %s\n", errors.c_str());
        return false;
    }

    if (!root.isArray()) {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: Config is not an array\n");
        return false;
    }

    for (const auto& entry : root) {
        SourceInfo si;
        si.id = entry.get("id", 0).asInt();
        si.name = entry.get("name", "").asString();
        si.type = entry.get("type", "").asString();
        si.pipeWireNodeName = entry.get("pipeWireNodeName", "").asString();
        si.enabled = entry.get("enabled", true).asBool();

        si.width = entry.get("width", 320).asInt();
        si.height = entry.get("height", 240).asInt();
        si.framerate = entry.get("framerate", 10).asInt();

        if (si.type == "videotestsrc") {
            si.pattern = entry.get("pattern", "smpte").asString();
        } else if (si.type == "v4l2src") {
            si.device = entry.get("device", "/dev/video0").asString();
        } else if (si.type == "rtspsrc") {
            si.uri = entry.get("uri", "").asString();
            si.latency = entry.get("latency", 200).asInt();
            if (si.latency < 0) si.latency = 0;
            if (si.latency > 10000) si.latency = 10000;
        } else if (si.type == "urisrc") {
            si.uri = entry.get("uri", "").asString();
            si.bufferSec = entry.get("bufferSec", 3.0).asDouble();
            if (si.bufferSec < 0) si.bufferSec = 0;
            if (si.bufferSec > 30) si.bufferSec = 30;
        } else if (si.type == "rtpsrc") {
            si.port = entry.get("port", 5004).asInt();
            si.encoding = entry.get("encoding", "H264").asString();
            si.multicastGroup = entry.get("multicastGroup", "").asString();
            if (si.port < 1024) si.port = 1024;
            if (si.port > 65535) si.port = 65535;
        }

        if (si.type.empty() || si.pipeWireNodeName.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoInputManager: Skipping source with missing type or node name\n");
            continue;
        }

        // Clamp to reasonable bounds
        if (si.width < 16) si.width = 16;
        if (si.width > 3840) si.width = 3840;
        if (si.height < 16) si.height = 16;
        if (si.height > 2160) si.height = 2160;
        if (si.framerate < 1) si.framerate = 1;
        if (si.framerate > 60) si.framerate = 60;

        m_sources.push_back(std::move(si));
    }

    return !m_sources.empty();
}

bool VideoInputManager::StartSource(SourceInfo& source) {
#ifdef HAS_GSTREAMER_VIDEO_INPUT
    if (source.running) {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: Source '%s' already running\n", source.name.c_str());
        return true;
    }

    setenv("PIPEWIRE_RUNTIME_DIR", "/run/pipewire-fpp", 0);

    // Build the source element portion of the pipeline
    std::string srcElement;
    bool useDecodebin = false;
    if (source.type == "videotestsrc") {
        srcElement = "videotestsrc is-live=true";
        if (!source.pattern.empty()) {
            srcElement += " pattern=" + source.pattern;
        }
    } else if (source.type == "v4l2src") {
        if (source.device.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoInputManager: v4l2src '%s' has no device path\n", source.name.c_str());
            return false;
        }
        srcElement = "v4l2src device=" + source.device;
    } else if (source.type == "rtspsrc") {
        if (source.uri.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoInputManager: rtspsrc '%s' has no URI\n", source.name.c_str());
            return false;
        }
        // rtspsrc → decodebin handles codec negotiation (H.264, H.265, MJPEG, etc.)
        srcElement = "rtspsrc location=" + source.uri
                   + " latency=" + std::to_string(source.latency)
                   + " protocols=tcp";
        useDecodebin = true;
    } else if (source.type == "urisrc") {
        if (source.uri.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoInputManager: urisrc '%s' has no URI\n", source.name.c_str());
            return false;
        }
        // souphttpsrc for HTTP/HTTPS URLs → decodebin auto-detects format
        long bufNs = (long)(source.bufferSec * 1000000000.0);
        srcElement = "souphttpsrc location=\"" + source.uri + "\""
                   + " is-live=true"
                   + " do-timestamp=true";
        useDecodebin = true;
    } else if (source.type == "rtpsrc") {
        // UDP RTP receiver with appropriate depayloader
        std::string caps;
        std::string depay;
        if (source.encoding == "H264") {
            caps = "application/x-rtp,media=video,encoding-name=H264,clock-rate=90000";
            depay = "rtph264depay";
        } else if (source.encoding == "H265") {
            caps = "application/x-rtp,media=video,encoding-name=H265,clock-rate=90000";
            depay = "rtph265depay";
        } else if (source.encoding == "JPEG") {
            caps = "application/x-rtp,media=video,encoding-name=JPEG,clock-rate=90000";
            depay = "rtpjpegdepay";
        } else if (source.encoding == "MP2T") {
            caps = "application/x-rtp,media=video,encoding-name=MP2T,clock-rate=90000";
            depay = "rtpmp2tdepay";
        } else {
            // RAW or unknown — use decodebin to auto-detect
            caps = "application/x-rtp";
            depay = "";
        }
        srcElement = "udpsrc port=" + std::to_string(source.port);
        if (!source.multicastGroup.empty()) {
            srcElement += " multicast-group=" + source.multicastGroup
                        + " auto-multicast=true";
        }
        srcElement += " caps=\"" + caps + "\"";
        if (!depay.empty()) {
            srcElement += " ! " + depay;
        }
        useDecodebin = true;
    } else {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: Unknown source type '%s'\n", source.type.c_str());
        return false;
    }

    // Build full pipeline:
    //   <source> ! capsfilter ! videoconvert ! queue ! pipewiresink(mode=provide)
    // For rtspsrc: rtspsrc ! decodebin ! videoconvert ! videoscale ! caps ! queue ! pipewiresink
    std::string capsStr = "video/x-raw,width=" + std::to_string(source.width)
                        + ",height=" + std::to_string(source.height)
                        + ",framerate=" + std::to_string(source.framerate) + "/1";

    std::string pipelineDesc;
    if (useDecodebin) {
        // RTSP: decodebin handles codec negotiation, videoscale + caps enforce resolution
        pipelineDesc = srcElement
            + " ! decodebin"
            + " ! videoconvert"
            + " ! videoscale"
            + " ! " + capsStr
            + " ! queue max-size-buffers=2 leaky=2"
            + " ! pipewiresink name=pwsink";
    } else {
        pipelineDesc = srcElement
            + " ! " + capsStr
            + " ! videoconvert"
            + " ! queue max-size-buffers=2 leaky=2"
            + " ! pipewiresink name=pwsink";
    }

    LogInfo(VB_MEDIAOUT, "VideoInputManager: Starting source '%s' (%s): %s\n",
            source.name.c_str(), source.type.c_str(), pipelineDesc.c_str());

    GError* error = nullptr;
    source.pipeline = gst_parse_launch(pipelineDesc.c_str(), &error);
    if (!source.pipeline) {
        LogErr(VB_MEDIAOUT, "VideoInputManager: Failed to create pipeline for '%s': %s\n",
               source.name.c_str(), error ? error->message : "unknown error");
        if (error) g_error_free(error);
        return false;
    }
    if (error) {
        LogWarn(VB_MEDIAOUT, "VideoInputManager: Pipeline warning for '%s': %s\n",
                source.name.c_str(), error->message);
        g_error_free(error);
    }

    // Configure pipewiresink for mode=provide with Video/Source media class
    GstElement* pwsink = gst_bin_get_by_name(GST_BIN(source.pipeline), "pwsink");
    if (pwsink) {
        std::string nodeDesc = "FPP Video Input: " + source.name;
        GstStructure* props = gst_structure_new("props",
            "media.class", G_TYPE_STRING, "Video/Source",
            "node.name", G_TYPE_STRING, source.pipeWireNodeName.c_str(),
            "node.description", G_TYPE_STRING, nodeDesc.c_str(),
            "node.autoconnect", G_TYPE_BOOLEAN, FALSE,
            "node.always-process", G_TYPE_BOOLEAN, TRUE,
            NULL);
        g_object_set(pwsink, "stream-properties", props, NULL);
        gst_structure_free(props);

        // mode=provide (enum value 2) — server-side provider node
        g_object_set(pwsink, "mode", PIPEWIRE_SINK_MODE_PROVIDE, NULL);
        g_object_set(pwsink, "async", FALSE, "sync", FALSE, NULL);

        gst_object_unref(pwsink);
    }

    // Start in a background thread — set_state(PLAYING) can block
    source.shutdownRequested = false;
    source.running = true;

    std::string sourceName = source.name;
    std::string nodeNameCopy = source.pipeWireNodeName;
    GstElement* pipeline = source.pipeline;
    std::atomic<bool>* shutdownFlag = &source.shutdownRequested;
    std::atomic<bool>* runningFlag = reinterpret_cast<std::atomic<bool>*>(&source.running);
    int* restartCountPtr = &source.restartCount;

    source.runThread = std::thread([pipeline, sourceName, nodeNameCopy, shutdownFlag, runningFlag, restartCountPtr]() {
        LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' thread starting pipeline (node=%s)\n",
                sourceName.c_str(), nodeNameCopy.c_str());

        GstStateChangeReturn ret = gst_element_set_state(pipeline, GST_STATE_PLAYING);
        if (ret == GST_STATE_CHANGE_FAILURE) {
            if (!shutdownFlag->load()) {
                LogWarn(VB_MEDIAOUT, "VideoInputManager: Source '%s' failed to start\n", sourceName.c_str());
            }
            *runningFlag = false;
            return;
        }

        LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' pipeline reached PLAYING\n", sourceName.c_str());

        // Monitor the pipeline bus for errors / EOS
        GstBus* bus = gst_pipeline_get_bus(GST_PIPELINE(pipeline));
        while (!shutdownFlag->load()) {
            GstMessage* msg = gst_bus_timed_pop(bus, 500 * GST_MSECOND);
            if (!msg) continue;

            switch (GST_MESSAGE_TYPE(msg)) {
                case GST_MESSAGE_ERROR: {
                    GError* err = nullptr;
                    gchar* debug = nullptr;
                    gst_message_parse_error(msg, &err, &debug);
                    if (!shutdownFlag->load()) {
                        LogWarn(VB_MEDIAOUT, "VideoInputManager: Source '%s' error: %s (%s)\n",
                                sourceName.c_str(), err ? err->message : "?", debug ? debug : "");
                    }
                    if (err) g_error_free(err);
                    g_free(debug);
                    gst_message_unref(msg);
                    gst_object_unref(bus);
                    *runningFlag = false;
                    return;
                }
                case GST_MESSAGE_EOS:
                    if (!shutdownFlag->load()) {
                        LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' received EOS\n", sourceName.c_str());
                    }
                    gst_message_unref(msg);
                    gst_object_unref(bus);
                    *runningFlag = false;
                    return;
                default:
                    break;
            }
            gst_message_unref(msg);
        }
        gst_object_unref(bus);
        *runningFlag = false;
        LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' thread exiting\n", sourceName.c_str());
    });

    LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' launched (node=%s)\n",
            source.name.c_str(), source.pipeWireNodeName.c_str());
    return true;
#else
    LogWarn(VB_MEDIAOUT, "VideoInputManager: GStreamer not available\n");
    return false;
#endif
}

void VideoInputManager::StopSource(SourceInfo& source) {
#ifdef HAS_GSTREAMER_VIDEO_INPUT
    source.shutdownRequested = true;

    if (source.pipeline) {
        gst_element_set_state(source.pipeline, GST_STATE_NULL);
    }

    if (source.runThread.joinable()) {
        source.runThread.join();
    }

    if (source.pipeline) {
        gst_object_unref(source.pipeline);
        source.pipeline = nullptr;
    }

    source.running = false;
    LogInfo(VB_MEDIAOUT, "VideoInputManager: Source '%s' stopped\n", source.name.c_str());
#endif
}

void VideoInputManager::StopAllSources() {
    for (auto& source : m_sources) {
        StopSource(source);
    }
}
