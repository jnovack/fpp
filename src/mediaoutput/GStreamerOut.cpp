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

#include "common.h"
#include "log.h"
#include "settings.h"

// Static instance pointer for callbacks
GStreamerOutput* GStreamerOutput::m_currentInstance = nullptr;

// Static audio sample buffer for WLED audio-reactive effects
std::array<float, GStreamerOutput::SAMPLE_BUFFER_SIZE> GStreamerOutput::s_sampleBuffer = {};
int GStreamerOutput::s_sampleWritePos = 0;
int GStreamerOutput::s_sampleRate = 0;
std::mutex GStreamerOutput::s_sampleMutex;

// One-time GStreamer initialization
static bool gst_initialized = false;
static void EnsureGStreamerInit() {
    if (!gst_initialized) {
        // Set PipeWire env vars so pipewiresink can find the FPP PipeWire runtime
        std::string audioBackend = getSetting("AudioBackend");
        if (audioBackend == "pipewire") {
            setenv("PIPEWIRE_RUNTIME_DIR", "/run/pipewire-fpp", 1);
            setenv("XDG_RUNTIME_DIR", "/run/pipewire-fpp", 1);
            setenv("PULSE_RUNTIME_PATH", "/run/pipewire-fpp/pulse", 1);
        }
        gst_init(nullptr, nullptr);
        gst_initialized = true;
        LogInfo(VB_MEDIAOUT, "GStreamer initialized: %s\n", gst_version_string());
    }
}

GStreamerOutput::GStreamerOutput(const std::string& mediaFilename, MediaOutputStatus* status, const std::string& videoOut)
    : m_videoOut(videoOut) {
    m_mediaFilename = mediaFilename;
    m_mediaOutputStatus = status;
    EnsureGStreamerInit();
    LogDebug(VB_MEDIAOUT, "GStreamerOutput::GStreamerOutput(%s)\n", mediaFilename.c_str());
}

GStreamerOutput::~GStreamerOutput() {
    Close();
}

int GStreamerOutput::Start(int msTime) {
    LogDebug(VB_MEDIAOUT, "GStreamerOutput::Start(%d) - %s\n", msTime, m_mediaFilename.c_str());

    // Build full path if not absolute
    std::string fullPath = m_mediaFilename;
    if (fullPath[0] != '/') {
        fullPath = FPP_DIR_MUSIC("/" + m_mediaFilename);
    }

    // Build the pipeline with tee for audio sample extraction
    // Main branch: audio output via PipeWire or autoaudiosink
    // Tap branch: appsink for WLED audio-reactive effects
    std::string pipelineSinkName = getSetting("PipeWireSinkName");
    
    std::string sinkStr;
    if (!pipelineSinkName.empty()) {
        sinkStr = "pipewiresink name=pwsink target-object=" + pipelineSinkName;
    } else {
        sinkStr = "autoaudiosink";
    }

    std::string pipelineStr =
        "filesrc location=\"" + fullPath + "\" ! decodebin ! audioconvert ! audioresample ! "
        "tee name=t "
        "t. ! queue ! volume name=vol ! " + sinkStr + " "
        "t. ! queue max-size-buffers=3 leaky=downstream ! "
        "audioconvert ! audio/x-raw,format=F32LE,channels=1 ! "
        "appsink name=sampletap emit-signals=true sync=false max-buffers=3 drop=true";
    
    LogDebug(VB_MEDIAOUT, "GStreamer pipeline: %s\n", pipelineStr.c_str());

    GError* error = nullptr;
    m_pipeline = gst_parse_launch(pipelineStr.c_str(), &error);
    if (error) {
        LogErr(VB_MEDIAOUT, "GStreamer pipeline error: %s\n", error->message);
        g_error_free(error);
        return 0;
    }

    if (!m_pipeline) {
        LogErr(VB_MEDIAOUT, "Failed to create GStreamer pipeline\n");
        return 0;
    }

    // Get the volume element for later control
    m_volume = gst_bin_get_by_name(GST_BIN(m_pipeline), "vol");

    // Get the appsink and connect the new-sample callback
    m_appsink = gst_bin_get_by_name(GST_BIN(m_pipeline), "sampletap");
    m_shutdownFlag.store(false);
    if (m_appsink) {
        m_appsinkSignalId = g_signal_connect(m_appsink, "new-sample", G_CALLBACK(OnNewSample), this);
        LogDebug(VB_MEDIAOUT, "GStreamer audio sample tap connected\n");
    } else {
        m_appsinkSignalId = 0;
        LogWarn(VB_MEDIAOUT, "GStreamer: could not find sampletap appsink element\n");
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
    m_bus = gst_element_get_bus(m_pipeline);

    // Install sync handler for autonomous bus message processing
    // This allows GStreamer playback to work without external Process() calls
    gst_bus_set_sync_handler(m_bus, BusSyncHandler, this, nullptr);

    // Seek to start position if non-zero
    GstStateChangeReturn ret = gst_element_set_state(m_pipeline, GST_STATE_PLAYING);
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

    if (m_mediaOutputStatus) {
        m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_PLAYING;
    }

    Starting();
    LogInfo(VB_MEDIAOUT, "GStreamer started playing: %s\n", m_mediaFilename.c_str());
    return 1;
}

int GStreamerOutput::Stop(void) {
    LogDebug(VB_MEDIAOUT, "GStreamerOutput::Stop()\n");
    if (m_pipeline) {
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
        if (gst_element_query_position(m_pipeline, GST_FORMAT_TIME, &pos) &&
            gst_element_query_duration(m_pipeline, GST_FORMAT_TIME, &dur)) {
            float elapsed = (float)pos / GST_SECOND;
            float remaining = (float)(dur - pos) / GST_SECOND;
            setMediaElapsed(elapsed, remaining);

            // Set total duration for playlist status reporting
            if (m_mediaOutputStatus && m_mediaOutputStatus->minutesTotal == 0 && m_mediaOutputStatus->secondsTotal == 0) {
                int totalSecs = (int)(dur / GST_SECOND);
                m_mediaOutputStatus->minutesTotal = totalSecs / 60;
                m_mediaOutputStatus->secondsTotal = totalSecs % 60;
                LogDebug(VB_MEDIAOUT, "GStreamer duration: %d:%02d\n",
                         m_mediaOutputStatus->minutesTotal, m_mediaOutputStatus->secondsTotal);
            }

            // Stall watchdog: detect when PipeWire stops consuming data
            // (e.g. HDMI sink unplugged causing combine-stream to block)
            if (pos != m_lastPosition) {
                m_lastPosition = pos;
                m_stallStartMs = 0; // position advancing, clear stall timer
            } else {
                // Position unchanged — start or continue stall timer
                uint64_t now = GetTimeMS();
                if (m_stallStartMs == 0) {
                    m_stallStartMs = now;
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
        LogDebug(VB_MEDIAOUT, "GStreamer sync: End of stream\n");
        if (self->m_loopCount > 0 || self->m_loopCount == -1) {
            if (self->m_loopCount > 0)
                self->m_loopCount--;
            gst_element_seek_simple(self->m_pipeline, GST_FORMAT_TIME,
                                    (GstSeekFlags)(GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_KEY_UNIT),
                                    0);
            LogDebug(VB_MEDIAOUT, "GStreamer sync: Looping (remaining: %d)\n", self->m_loopCount);
        } else {
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
        LogErr(VB_MEDIAOUT, "GStreamer sync error: %s\n", err->message);
        LogDebug(VB_MEDIAOUT, "GStreamer sync debug: %s\n", debug ? debug : "(none)");
        g_error_free(err);
        g_free(debug);
        self->m_playing = false;
        if (self->m_mediaOutputStatus) {
            self->m_mediaOutputStatus->status = MEDIAOUTPUTSTATUS_IDLE;
        }
        self->Stopping();
        self->Stopped();
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
        // Set shutdown flag FIRST — this prevents OnNewSample from doing any work
        m_shutdownFlag.store(true);

        // Disconnect the appsink signal and disable emission BEFORE pipeline state change.
        // This prevents the streaming thread from calling OnNewSample during teardown,
        // which can deadlock with gst_element_set_state(NULL) due to malloc arena locks.
        if (m_appsink) {
            if (m_appsinkSignalId > 0) {
                g_signal_handler_disconnect(m_appsink, m_appsinkSignalId);
                m_appsinkSignalId = 0;
            }
            g_object_set(m_appsink, "emit-signals", FALSE, NULL);
        }

        // Remove bus sync handler before state change
        if (m_bus) {
            gst_bus_set_sync_handler(m_bus, nullptr, nullptr, nullptr);
        }
        Stop();
        if (m_appsink) {
            gst_object_unref(m_appsink);
            m_appsink = nullptr;
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
    if (m_currentInstance == this) {
        m_currentInstance = nullptr;
    }
    return 1;
}

int GStreamerOutput::IsPlaying(void) {
    return m_playing ? 1 : 0;
}

int GStreamerOutput::AdjustSpeed(float masterPos) {
    // Phase 5 — will port VLC rate-matching algorithm
    // For now, no-op (master units don't need speed adjustment)
    return 1;
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

// Static methods — stubs for later phases
bool GStreamerOutput::IsOverlayingVideo() {
    // Phase 3
    return false;
}

bool GStreamerOutput::ProcessVideoOverlay(unsigned int msTimestamp) {
    // Phase 3
    return false;
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

#endif // HAS_GSTREAMER
