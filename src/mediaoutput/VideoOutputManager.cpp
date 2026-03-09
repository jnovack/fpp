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

#include "VideoOutputManager.h"
#include "common.h"
#include "settings.h"
#include "log.h"
#include "overlays/PixelOverlay.h"
#include "overlays/PixelOverlayModel.h"

#include <fstream>
#include <cstring>
#include <arpa/inet.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <ifaddrs.h>

#if __has_include(<gst/app/gstappsink.h>)
#include <gst/app/gstappsink.h>
#endif

#if __has_include(<jsoncpp/json/json.h>)
#include <jsoncpp/json/json.h>
#elif __has_include(<json/json.h>)
#include <json/json.h>
#endif

// SAP (Session Announcement Protocol) constants — RFC 2974
static constexpr const char* SAP_MCAST_ADDR = "239.255.255.255";
static constexpr int SAP_PORT              = 9875;
static constexpr int SAP_TTL               = 255;
static constexpr int SAP_VERSION           = 1;
static constexpr int SAP_INTERVAL_S        = 30;

VideoOutputManager& VideoOutputManager::Instance() {
    static VideoOutputManager instance;
    return instance;
}

VideoOutputManager::~VideoOutputManager() {
    Shutdown();
}

void VideoOutputManager::Init() {
    std::lock_guard<std::mutex> lock(m_mutex);
    if (m_initialized)
        return;

    // Only init if PipeWire backend is active
    std::string backend = getSetting("AudioBackend");
    if (backend != "pipewire") {
        LogDebug(VB_MEDIAOUT, "VideoOutputManager: Skipping init (AudioBackend=%s, not pipewire)\n", backend.c_str());
        return;
    }

    // Check if video routing is configured
    std::string videoSink = getSetting("PipeWireVideoSinkName");
    if (videoSink.empty()) {
        LogDebug(VB_MEDIAOUT, "VideoOutputManager: No PipeWireVideoSinkName configured, skipping\n");
        return;
    }

    m_initialized = true;

    // Load config but don't start on-demand consumers yet — those start
    // when GStreamerOut calls StartConsumers() after the producer is ready.
    // However, consumers with a sourceNode (targeting a persistent video
    // input source) can start immediately.
    if (LoadConfig()) {
        int persistentCount = 0;
        for (auto& consumer : m_consumers) {
            if (!consumer.sourceNode.empty()) {
                StartConsumer(consumer);
                persistentCount++;
            }
        }
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Initialized with %d consumer(s) (%d persistent, %d on-demand)\n",
                (int)m_consumers.size(), persistentCount, (int)m_consumers.size() - persistentCount);
        // Start SAP multicast announcer for any persistent RTP consumers
        StartSAPAnnouncer();
    }
}

void VideoOutputManager::Reload() {
    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Reloading configuration\n");

    // Stop SAP before taking the lock (SAP thread needs m_mutex)
    StopSAPAnnouncer();

    std::string savedProducer;
    {
        std::lock_guard<std::mutex> lock(m_mutex);
        StopAllConsumers();
        m_consumers.clear();
        savedProducer = m_activeProducer;
    }

    // Re-check if video routing is configured
    std::string videoSink = getSetting("PipeWireVideoSinkName");
    if (videoSink.empty()) {
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Video routing disabled (no PipeWireVideoSinkName)\n");
        return;
    }

    bool shouldStartSAP = false;
    {
        std::lock_guard<std::mutex> lock(m_mutex);
        m_initialized = true;

        if (LoadConfig()) {
            LogInfo(VB_MEDIAOUT, "VideoOutputManager: Reloaded with %d consumer(s)\n", (int)m_consumers.size());
            // If a producer was active, restart consumers targeting it
            if (!savedProducer.empty()) {
                m_activeProducer = savedProducer;
                for (auto& consumer : m_consumers) {
                    if (consumer.type == "rtp")
                        shouldStartSAP = true;
                    StartConsumer(consumer);
                }
            }
        }
    }
    if (shouldStartSAP)
        StartSAPAnnouncer();
}

void VideoOutputManager::Shutdown() {
    // Stop SAP announcer first — outside the lock because SAP thread needs m_mutex
    StopSAPAnnouncer();

    std::lock_guard<std::mutex> lock(m_mutex);
    StopAllConsumers();
    m_consumers.clear();
    m_activeProducer.clear();
    m_initialized = false;
    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Shutdown complete\n");
}

void VideoOutputManager::StartConsumers(const std::string& producerNodeName, int primaryConnectorId) {
    bool shouldStartSAP = false;
    {
        std::lock_guard<std::mutex> lock(m_mutex);
        if (!m_initialized || m_consumers.empty()) {
            return;
        }

        m_activeProducer = producerNodeName;
        m_primaryConnectorId = primaryConnectorId;

        // Extract stream slot number from producer node name (e.g. "fppd_video_stream_2" → 2)
        int producerSlot = 0;
        {
            const std::string prefix = "fppd_video_stream_";
            auto pos = producerNodeName.find(prefix);
            if (pos != std::string::npos) {
                std::string slotStr = producerNodeName.substr(pos + prefix.size());
                try { producerSlot = std::stoi(slotStr); } catch (...) {}
            }
        }

        // Only start consumers that don't have a fixed sourceNode — those
        // depend on fppd media playback.  Consumers with sourceNode are
        // already running (started at Init).
        // If the consumer has streamSlots configured, only start it for matching slots.
        int started = 0;
        for (auto& consumer : m_consumers) {
            if (consumer.sourceNode.empty()) {
                // Check stream slot filter
                if (!consumer.streamSlots.empty() && producerSlot > 0) {
                    bool slotMatch = false;
                    for (int s : consumer.streamSlots) {
                        if (s == producerSlot) { slotMatch = true; break; }
                    }
                    if (!slotMatch) continue;
                }
                if (consumer.type == "rtp")
                    shouldStartSAP = true;
                StartConsumer(consumer);
                started++;
            }
        }
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Started %d on-demand consumer(s) targeting producer '%s' (slot %d)\n",
                started, producerNodeName.c_str(), producerSlot);
    }
    // Start SAP announcer outside the lock (SAPAnnounceLoop needs m_mutex)
    if (shouldStartSAP)
        StartSAPAnnouncer();
}

void VideoOutputManager::StopConsumers() {
    bool shouldStopSAP = false;
    {
        std::lock_guard<std::mutex> lock(m_mutex);
        if (!m_initialized) return;

        // Only stop on-demand consumers (those without a fixed sourceNode).
        // Persistent-source consumers keep running independently of media playback.
        int stopped = 0;
        for (auto& consumer : m_consumers) {
            if (consumer.sourceNode.empty() && consumer.running) {
                StopConsumer(consumer);
                stopped++;
            }
        }
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Stopped %d on-demand consumer(s) (producer gone)\n", stopped);
        m_activeProducer.clear();

        // Check if any RTP consumers are still running (persistent-source)
        bool hasRunningRtp = false;
        for (const auto& c : m_consumers) {
            if (c.type == "rtp" && c.running) { hasRunningRtp = true; break; }
        }
        if (!hasRunningRtp)
            shouldStopSAP = true;
    }
    if (shouldStopSAP)
        StopSAPAnnouncer();
}

bool VideoOutputManager::HasConsumers() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    return !m_consumers.empty();
}

bool VideoOutputManager::HasActiveConsumers() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    for (const auto& c : m_consumers) {
        if (c.running)
            return true;
    }
    return false;
}

int VideoOutputManager::ActiveConsumerCount() const {
    std::lock_guard<std::mutex> lock(m_mutex);
    int count = 0;
    for (const auto& c : m_consumers) {
        if (c.running)
            count++;
    }
    return count;
}

bool VideoOutputManager::LoadConfig() {
    std::string configPath = FPP_DIR_MEDIA("/config/pipewire-video-consumers.json");

    if (!FileExists(configPath)) {
        LogDebug(VB_MEDIAOUT, "VideoOutputManager: No consumer config at %s\n", configPath.c_str());
        return false;
    }

    std::ifstream ifs(configPath);
    if (!ifs.is_open()) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Cannot open %s\n", configPath.c_str());
        return false;
    }

    Json::Value root;
    Json::CharReaderBuilder builder;
    std::string errors;
    if (!Json::parseFromStream(builder, ifs, &root, &errors)) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: JSON parse error: %s\n", errors.c_str());
        return false;
    }

    if (!root.isArray()) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Config is not an array\n");
        return false;
    }

    for (const auto& entry : root) {
        ConsumerInfo ci;
        ci.id = entry.get("id", 0).asInt();
        ci.name = entry.get("name", "").asString();
        ci.type = entry.get("type", "").asString();
        ci.pipeWireNodeName = entry.get("pipeWireNodeName", "").asString();

        if (ci.type == "hdmi") {
            ci.connector = entry.get("connector", "").asString();
            ci.cardPath = entry.get("cardPath", "").asString();
            ci.connectorId = entry.get("connectorId", -1).asInt();
            ci.width = entry.get("width", 0).asInt();
            ci.height = entry.get("height", 0).asInt();
            ci.scaling = entry.get("scaling", "fit").asString();
        } else if (ci.type == "overlay") {
            ci.overlayModel = entry.get("overlayModel", "").asString();
        } else if (ci.type == "rtp") {
            ci.address = entry.get("address", "239.0.0.1").asString();
            ci.port = entry.get("port", 5004).asInt();
            ci.rtpEncoding = entry.get("encoding", "raw").asString();
        }

        if (ci.type.empty() || ci.pipeWireNodeName.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoOutputManager: Skipping consumer with missing type or node name\n");
            continue;
        }

        ci.sourceNode = entry.get("sourceNode", "").asString();

        if (entry.isMember("streamSlots") && entry["streamSlots"].isArray()) {
            for (const auto& s : entry["streamSlots"]) {
                int slot = s.asInt();
                if (slot >= 1 && slot <= 5)
                    ci.streamSlots.push_back(slot);
            }
        }

        m_consumers.push_back(std::move(ci));
    }

    return !m_consumers.empty();
}

bool VideoOutputManager::StartConsumer(ConsumerInfo& consumer) {
#ifdef HAS_GSTREAMER_VIDEO_OUTPUT
    if (consumer.running) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' already running\n", consumer.name.c_str());
        return true;
    }

    // Set PipeWire env (same as GStreamerOut)
    setenv("PIPEWIRE_RUNTIME_DIR", "/run/pipewire-fpp", 0);

    // Build GStreamer consumer pipeline description
    std::string pipelineDesc;
    std::string nodeDesc = "FPP Video: " + consumer.name;

    // Common source: pipewiresrc with Stream/Input/Video media class
    // The pipewiresrc will wait for a producer to connect via PipeWire
    pipelineDesc = "pipewiresrc name=src do-timestamp=true ! videoconvert ! videoscale ! ";

    if (consumer.type == "hdmi") {
        if (consumer.connectorId <= 0 || consumer.cardPath.empty()) {
            LogWarn(VB_MEDIAOUT, "VideoOutputManager: HDMI consumer '%s' has no valid connector\n", consumer.name.c_str());
            return false;
        }

        // Check for conflict with the primary video output — the main GStreamerOut
        // pipeline always has a direct kmssink on the primary connector (even when
        // PipeWire video routing is active, kmssink stays in the primary pipeline
        // to hold DRM master).  Two kmssinks cannot share the same DRM CRTC/connector.
        // Use the actual connector ID (which may differ from VideoOutput setting due to
        // auto-fallback when the configured connector is disconnected).
        if (m_primaryConnectorId >= 0 && consumer.connectorId == m_primaryConnectorId) {
            LogWarn(VB_MEDIAOUT, "VideoOutputManager: Skipping HDMI consumer '%s' — "
                    "connector %s (id=%d) is used by the primary video output\n",
                    consumer.name.c_str(), consumer.connector.c_str(), consumer.connectorId);
            return false;
        }

        // Add scaling caps with format suitable for kmssink (vc4 DRM).
        // kmssink needs an explicit format — without it, pipewiresrc may
        // negotiate a format kmssink can't render, causing "Error calculating
        // the output display ratio" failures.
        if (consumer.width > 0 && consumer.height > 0) {
            pipelineDesc += "video/x-raw,format=BGRx,width=" + std::to_string(consumer.width)
                         + ",height=" + std::to_string(consumer.height) + " ! ";
        } else {
            pipelineDesc += "video/x-raw,format=BGRx ! ";
        }

        pipelineDesc += "kmssink name=sink driver-name=vc4"
                     " connector-id=" + std::to_string(consumer.connectorId)
                     + " restore-crtc=true skip-vsync=true";

    } else if (consumer.type == "overlay") {
        // Resolve the pixel overlay model to get its dimensions
        PixelOverlayModel* model = PixelOverlayManager::INSTANCE.getModel(consumer.overlayModel);
        if (!model) {
            LogWarn(VB_MEDIAOUT, "VideoOutputManager: Overlay model '%s' not found for consumer '%s'\n",
                    consumer.overlayModel.c_str(), consumer.name.c_str());
            return false;
        }

        int overlayW = model->getWidth();
        int overlayH = model->getHeight();
        if (overlayW <= 0 || overlayH <= 0) {
            LogWarn(VB_MEDIAOUT, "VideoOutputManager: Overlay model '%s' has invalid size %dx%d\n",
                    consumer.overlayModel.c_str(), overlayW, overlayH);
            return false;
        }

        // Set up shared overlay state for the appsink callback
        consumer.overlayState = std::make_shared<OverlayState>();
        consumer.overlayState->model = model;
        consumer.overlayState->width = overlayW;
        consumer.overlayState->height = overlayH;
        consumer.overlayState->active = true;

        // Register model listener so we track model replacement/deletion
        std::weak_ptr<OverlayState> weakState = consumer.overlayState;
        PixelOverlayManager::INSTANCE.addModelListener(consumer.overlayModel,
            "VideoOutputManager_" + consumer.pipeWireNodeName,
            [weakState](PixelOverlayModel* m) {
                if (auto state = weakState.lock()) {
                    std::lock_guard<std::mutex> lock(state->mtx);
                    state->model = m;
                    if (m) {
                        state->width = m->getWidth();
                        state->height = m->getHeight();
                    }
                }
            });

        // Build pipeline: pipewiresrc → videoconvert → videoscale → capsfilter(RGB, WxH) → appsink
        pipelineDesc += "video/x-raw,format=RGB,width=" + std::to_string(overlayW)
                     + ",height=" + std::to_string(overlayH)
                     + " ! appsink name=sink emit-signals=true sync=true max-buffers=2 drop=true";

        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Overlay consumer '%s' targeting model '%s' (%dx%d)\n",
                consumer.name.c_str(), consumer.overlayModel.c_str(), overlayW, overlayH);

    } else if (consumer.type == "rtp") {
        // RTP video output — encoding-dependent pipeline
        std::string enc = consumer.rtpEncoding;
        if (enc.empty()) enc = "raw";

        if (enc == "h264") {
            pipelineDesc += "x264enc tune=zerolatency bitrate=4000 speed-preset=ultrafast"
                           " key-int-max=30 bframes=0"
                           " ! video/x-h264,profile=baseline"
                           " ! rtph264pay config-interval=1 pt=96";
        } else if (enc == "h265") {
            pipelineDesc += "x265enc tune=zerolatency bitrate=3000 speed-preset=ultrafast"
                           " key-int-max=30"
                           " ! rtph265pay config-interval=1 pt=96";
        } else if (enc == "mjpeg") {
            pipelineDesc += "jpegenc quality=80 ! rtpjpegpay pt=26";
        } else {
            pipelineDesc += "rtpvrawpay pt=96";
        }

        pipelineDesc += " ! udpsink host=" + consumer.address
                     + " port=" + std::to_string(consumer.port)
                     + " auto-multicast=true";

        // Generate SDP file for receivers (VLC, ffplay, etc.)
        WriteSdpFile(consumer, enc);

    } else {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Unknown consumer type '%s'\n", consumer.type.c_str());
        return false;
    }

    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Starting consumer '%s' (%s): %s\n",
            consumer.name.c_str(), consumer.type.c_str(), pipelineDesc.c_str());

    GError* error = nullptr;
    consumer.pipeline = gst_parse_launch(pipelineDesc.c_str(), &error);
    if (!consumer.pipeline) {
        LogErr(VB_MEDIAOUT, "VideoOutputManager: Failed to create pipeline for '%s': %s\n",
               consumer.name.c_str(), error ? error->message : "unknown error");
        if (error) g_error_free(error);
        return false;
    }
    if (error) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Pipeline warning for '%s': %s\n",
                consumer.name.c_str(), error->message);
        g_error_free(error);
    }

    // Set pipewiresrc stream properties for PipeWire identification
    GstElement* src = gst_bin_get_by_name(GST_BIN(consumer.pipeline), "src");
    if (src) {
        GstStructure* props = gst_structure_new("props",
            "media.class", G_TYPE_STRING, "Stream/Input/Video",
            "node.name", G_TYPE_STRING, consumer.pipeWireNodeName.c_str(),
            "node.description", G_TYPE_STRING, nodeDesc.c_str(),
            NULL);
        g_object_set(src, "stream-properties", props, NULL);
        gst_structure_free(props);

        // Set the target-object so PipeWire links this consumer to the
        // video producer node that's currently active.
        std::string targetNode = consumer.sourceNode.empty()
            ? m_activeProducer : consumer.sourceNode;
        g_object_set(src, "target-object", targetNode.c_str(), NULL);

        gst_object_unref(src);
    }

    // Wire up appsink callback for overlay consumers
    if (consumer.type == "overlay" && consumer.overlayState) {
        GstElement* sink = gst_bin_get_by_name(GST_BIN(consumer.pipeline), "sink");
        if (sink) {
            GstAppSinkCallbacks callbacks = {};
            callbacks.new_sample = OnOverlaySample;
            // Pass the shared_ptr's raw pointer as user_data — the shared_ptr
            // in ConsumerInfo keeps it alive for the consumer's lifetime.
            gst_app_sink_set_callbacks(GST_APP_SINK(sink),
                &callbacks, consumer.overlayState.get(), nullptr);
            gst_object_unref(sink);
        }
    }

    // Start the pipeline in a background thread because set_state(PLAYING)
    // blocks until pipewiresrc prerolls (i.e. a PipeWire producer links).
    // Without a background thread this would block the command handler thread.
    consumer.shutdownRequested = false;
    consumer.running = true;

    std::string consumerName = consumer.name;
    std::string sourceNodeName = consumer.sourceNode;
    GstElement* pipeline = consumer.pipeline;
    std::atomic<bool>* shutdownFlag = &consumer.shutdownRequested;

    consumer.startThread = std::thread([pipeline, consumerName, sourceNodeName, shutdownFlag]() {
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' background thread starting pipeline\n",
                consumerName.c_str());

        // Persistent-source consumers may need to retry if the producer
        // node hasn't registered yet (e.g. during startup race).
        // On-demand consumers don't retry — the PipeWire producer node
        // is already in the pipeline when StartConsumers() is called.
        int maxRetries = sourceNodeName.empty() ? 0 : 5;
        int retryDelay = 1; // seconds
        GstStateChangeReturn ret = GST_STATE_CHANGE_FAILURE;

        for (int attempt = 0; attempt <= maxRetries; attempt++) {
            if (shutdownFlag->load()) return;

            if (attempt > 0) {
                LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' retry %d/%d "
                        "(waiting for source '%s')\n",
                        consumerName.c_str(), attempt, maxRetries, sourceNodeName.c_str());
                gst_element_set_state(pipeline, GST_STATE_NULL);
                for (int w = 0; w < retryDelay * 10 && !shutdownFlag->load(); w++)
                    std::this_thread::sleep_for(std::chrono::milliseconds(100));
                if (shutdownFlag->load()) return;
                retryDelay = std::min(retryDelay * 2, 8);
            }

            ret = gst_element_set_state(pipeline, GST_STATE_PLAYING);
            if (ret != GST_STATE_CHANGE_FAILURE) break;
        }

        if (ret == GST_STATE_CHANGE_FAILURE) {
            // Check if we were asked to shut down — don't log as error if so
            if (!shutdownFlag->load()) {
                LogWarn(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' pipeline failed to start "
                        "(connector may be disconnected)\n", consumerName.c_str());
            }
            return;
        }

        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' pipeline reached PLAYING (ret=%d)\n",
                consumerName.c_str(), ret);

        // Monitor the pipeline bus for errors / EOS while running
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
                        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' error: %s (%s)\n",
                                consumerName.c_str(), err ? err->message : "?", debug ? debug : "");
                    }
                    if (err) g_error_free(err);
                    g_free(debug);
                    gst_message_unref(msg);
                    gst_object_unref(bus);
                    return;
                }
                case GST_MESSAGE_EOS:
                    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' received EOS\n",
                            consumerName.c_str());
                    gst_message_unref(msg);
                    gst_object_unref(bus);
                    return;
                default:
                    break;
            }
            gst_message_unref(msg);
        }
        gst_object_unref(bus);
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' background thread exiting\n",
                consumerName.c_str());
    });

    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' launched in background thread\n",
            consumer.name.c_str());
    return true;
#else
    LogWarn(VB_MEDIAOUT, "VideoOutputManager: GStreamer not available\n");
    return false;
#endif
}

#ifdef HAS_GSTREAMER_VIDEO_OUTPUT
GstFlowReturn VideoOutputManager::OnOverlaySample(GstAppSink* appsink, gpointer userData) {
    auto* state = static_cast<OverlayState*>(userData);
    if (!state || !state->active.load())
        return GST_FLOW_EOS;

    GstSample* sample = gst_app_sink_pull_sample(appsink);
    if (!sample)
        return GST_FLOW_OK;

    GstBuffer* buffer = gst_sample_get_buffer(sample);
    GstMapInfo map;
    if (gst_buffer_map(buffer, &map, GST_MAP_READ)) {
        std::lock_guard<std::mutex> lock(state->mtx);
        PixelOverlayModel* model = state->model;
        if (model) {
            int w = state->width;
            int h = state->height;
            int rowBytes = w * 3;
            int expectedSize = rowBytes * h;
            int stride = (h > 0) ? (int)(map.size / h) : rowBytes;

            if (stride == rowBytes && (int)map.size >= expectedSize) {
                // No padding — push directly
                model->setData(map.data);
            } else if (stride > rowBytes) {
                // GStreamer row padding — strip it
                std::vector<uint8_t> stripped(expectedSize);
                for (int y = 0; y < h; y++) {
                    memcpy(&stripped[y * rowBytes], map.data + y * stride, rowBytes);
                }
                model->setData(stripped.data());
            }

            // Auto-enable model if it was disabled
            if (model->getState() == PixelOverlayState::Disabled) {
                state->wasDisabled = true;
                model->setState(PixelOverlayState(PixelOverlayState::Enabled));
            }
        }
        gst_buffer_unmap(buffer, &map);
    }

    gst_sample_unref(sample);
    return GST_FLOW_OK;
}
#endif

void VideoOutputManager::StopConsumer(ConsumerInfo& consumer) {
#ifdef HAS_GSTREAMER_VIDEO_OUTPUT
    // Signal the background thread to exit first
    consumer.shutdownRequested = true;

    // Deactivate overlay state before pipeline teardown to prevent
    // the appsink callback from accessing a model during shutdown
    if (consumer.overlayState) {
        consumer.overlayState->active = false;
    }

    // Set pipeline to NULL — this unblocks pipewiresrc and any pending operations
    if (consumer.pipeline) {
        gst_element_set_state(consumer.pipeline, GST_STATE_NULL);
    }

    // Wait for the background thread to finish before releasing resources
    if (consumer.startThread.joinable()) {
        consumer.startThread.join();
    }

    // Now safe to unref the pipeline (no other thread uses it)
    if (consumer.pipeline) {
        gst_object_unref(consumer.pipeline);
        consumer.pipeline = nullptr;
    }

    // Restore overlay model state and remove listener
    if (consumer.overlayState) {
        std::lock_guard<std::mutex> lock(consumer.overlayState->mtx);
        if (consumer.overlayState->wasDisabled && consumer.overlayState->model) {
            consumer.overlayState->model->setState(PixelOverlayState(PixelOverlayState::Disabled));
            consumer.overlayState->wasDisabled = false;
        }
        consumer.overlayState->model = nullptr;
        PixelOverlayManager::INSTANCE.removeModelListener(consumer.overlayModel,
            "VideoOutputManager_" + consumer.pipeWireNodeName);
        consumer.overlayState.reset();
    }

    consumer.running = false;
    LogInfo(VB_MEDIAOUT, "VideoOutputManager: Consumer '%s' stopped\n", consumer.name.c_str());
#endif
}

void VideoOutputManager::StopAllConsumers() {
    for (auto& consumer : m_consumers) {
        StopConsumer(consumer);
    }
}

std::string VideoOutputManager::BuildSdp(const ConsumerInfo& consumer, const std::string& encoding) {
    // Build SDP content describing the RTP stream.
    // See RFC 4566 (SDP), RFC 3984 (H.264 RTP), RFC 4175 (raw video RTP).
    std::string sdp;
    sdp += "v=0\r\n";
    sdp += "o=FPP 0 0 IN IP4 " + consumer.address + "\r\n";
    sdp += "s=FPP Video: " + consumer.name + "\r\n";
    sdp += "c=IN IP4 " + consumer.address + "/32\r\n";
    sdp += "t=0 0\r\n";

    if (encoding == "h264") {
        sdp += "m=video " + std::to_string(consumer.port) + " RTP/AVP 96\r\n";
        sdp += "a=rtpmap:96 H264/90000\r\n";
        sdp += "a=fmtp:96 packetization-mode=1\r\n";
    } else if (encoding == "h265") {
        sdp += "m=video " + std::to_string(consumer.port) + " RTP/AVP 96\r\n";
        sdp += "a=rtpmap:96 H265/90000\r\n";
    } else if (encoding == "mjpeg") {
        sdp += "m=video " + std::to_string(consumer.port) + " RTP/AVP 26\r\n";
        sdp += "a=rtpmap:26 JPEG/90000\r\n";
    } else {
        // raw video (rtpvrawpay) — RFC 4175
        sdp += "m=video " + std::to_string(consumer.port) + " RTP/AVP 96\r\n";
        sdp += "a=rtpmap:96 raw/90000\r\n";
    }

    return sdp;
}

void VideoOutputManager::WriteSdpFile(const ConsumerInfo& consumer, const std::string& encoding) {
    std::string sdp = BuildSdp(consumer, encoding);

    // Write to web-accessible location: /home/fpp/media/config/
    std::string sdpPath = FPP_DIR_MEDIA("/config/rtp_" + consumer.pipeWireNodeName + ".sdp");
    std::ofstream ofs(sdpPath);
    if (ofs.is_open()) {
        ofs << sdp;
        ofs.close();
        LogInfo(VB_MEDIAOUT, "VideoOutputManager: Wrote SDP file %s\n", sdpPath.c_str());
    } else {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager: Failed to write SDP file %s\n", sdpPath.c_str());
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// SAP (Session Announcement Protocol) — RFC 2974
// ──────────────────────────────────────────────────────────────────────────────

static std::string GetLocalIPAddress() {
    // Return the first non-loopback IPv4 address
    struct ifaddrs* ifas = nullptr;
    if (getifaddrs(&ifas) != 0)
        return "0.0.0.0";

    std::string result = "0.0.0.0";
    for (struct ifaddrs* ifa = ifas; ifa; ifa = ifa->ifa_next) {
        if (!ifa->ifa_addr || ifa->ifa_addr->sa_family != AF_INET)
            continue;
        auto* sin = (struct sockaddr_in*)ifa->ifa_addr;
        uint32_t ip = ntohl(sin->sin_addr.s_addr);
        if ((ip >> 24) == 127)
            continue;
        char buf[INET_ADDRSTRLEN];
        inet_ntop(AF_INET, &sin->sin_addr, buf, sizeof(buf));
        result = buf;
        break;
    }
    freeifaddrs(ifas);
    return result;
}

std::vector<uint8_t> VideoOutputManager::BuildSAPPacket(const std::string& sourceIP,
                                                        uint16_t msgIdHash,
                                                        const std::string& sdp,
                                                        bool isDeletion) {
    // RFC 2974 SAP packet:
    //   Byte 0: V=1 (bits 7-5), A=0 (bit 4), R=0 (bit 3), T=isDeletion (bit 2),
    //           E=0 (bit 1), C=0 (bit 0)
    //   Byte 1: Auth length = 0
    //   Bytes 2-3: Message ID hash (network byte order)
    //   Bytes 4-7: Originating source (IPv4)
    //   Payload type: "application/sdp\0"
    //   SDP data
    uint8_t header0 = (SAP_VERSION << 5);  // V=1
    if (isDeletion) {
        header0 |= 0x04;  // T=1 (deletion)
    }

    struct in_addr srcAddr;
    inet_pton(AF_INET, sourceIP.c_str(), &srcAddr);

    std::vector<uint8_t> packet;
    packet.reserve(8 + 16 + sdp.size());
    packet.push_back(header0);
    packet.push_back(0);  // auth length
    packet.push_back((msgIdHash >> 8) & 0xFF);
    packet.push_back(msgIdHash & 0xFF);

    uint8_t* ipBytes = reinterpret_cast<uint8_t*>(&srcAddr.s_addr);
    packet.push_back(ipBytes[0]);
    packet.push_back(ipBytes[1]);
    packet.push_back(ipBytes[2]);
    packet.push_back(ipBytes[3]);

    const char* payloadType = "application/sdp";
    for (const char* p = payloadType; *p; ++p)
        packet.push_back(static_cast<uint8_t>(*p));
    packet.push_back(0);  // NUL terminator

    for (char c : sdp)
        packet.push_back(static_cast<uint8_t>(c));

    return packet;
}

void VideoOutputManager::StartSAPAnnouncer() {
    if (m_sapRunning.load())
        return;

    // Only start if we have at least one RTP consumer
    bool hasRtp = false;
    for (const auto& c : m_consumers) {
        if (c.type == "rtp" && c.running) {
            hasRtp = true;
            break;
        }
    }
    if (!hasRtp)
        return;

    m_sapRunning = true;
    m_sapThread = std::thread(&VideoOutputManager::SAPAnnounceLoop, this);
}

void VideoOutputManager::StopSAPAnnouncer() {
    if (!m_sapRunning.load())
        return;

    m_sapRunning = false;
    if (m_sapThread.joinable())
        m_sapThread.join();
}

void VideoOutputManager::SAPAnnounceLoop() {
    LogInfo(VB_MEDIAOUT, "VideoOutputManager SAP announcer thread started\n");

    int sock = socket(AF_INET, SOCK_DGRAM, 0);
    if (sock < 0) {
        LogErr(VB_MEDIAOUT, "VideoOutputManager SAP: Failed to create socket: %s\n", strerror(errno));
        return;
    }

    int ttl = SAP_TTL;
    setsockopt(sock, IPPROTO_IP, IP_MULTICAST_TTL, &ttl, sizeof(ttl));

    struct sockaddr_in sapAddr;
    memset(&sapAddr, 0, sizeof(sapAddr));
    sapAddr.sin_family = AF_INET;
    sapAddr.sin_port = htons(SAP_PORT);
    inet_pton(AF_INET, SAP_MCAST_ADDR, &sapAddr.sin_addr);

    std::string localIP = GetLocalIPAddress();

    // Build announce & delete packets for each running RTP consumer
    struct SAPEntry {
        std::vector<uint8_t> announcePacket;
        std::vector<uint8_t> deletePacket;
    };
    std::vector<SAPEntry> entries;

    {
        std::lock_guard<std::mutex> lock(m_mutex);
        for (const auto& consumer : m_consumers) {
            if (consumer.type != "rtp" || !consumer.running)
                continue;

            std::string enc = consumer.rtpEncoding.empty() ? "raw" : consumer.rtpEncoding;
            std::string sdp = BuildSdp(consumer, enc);

            // Simple hash from consumer id + port
            uint16_t hash = static_cast<uint16_t>(consumer.id * 31 + consumer.port);

            SAPEntry entry;
            entry.announcePacket = BuildSAPPacket(localIP, hash, sdp, false);
            entry.deletePacket = BuildSAPPacket(localIP, hash, sdp, true);
            entries.push_back(std::move(entry));
        }
    }

    if (entries.empty()) {
        LogWarn(VB_MEDIAOUT, "VideoOutputManager SAP: No RTP consumers to announce\n");
    } else {
        LogInfo(VB_MEDIAOUT, "VideoOutputManager SAP: Announcing %d stream(s) to %s:%d every %ds\n",
                (int)entries.size(), SAP_MCAST_ADDR, SAP_PORT, SAP_INTERVAL_S);
    }

    while (m_sapRunning.load()) {
        for (const auto& entry : entries) {
            ssize_t sent = sendto(sock, entry.announcePacket.data(), entry.announcePacket.size(), 0,
                                  (struct sockaddr*)&sapAddr, sizeof(sapAddr));
            if (sent < 0) {
                LogErr(VB_MEDIAOUT, "VideoOutputManager SAP: sendto failed: %s\n", strerror(errno));
            }
        }

        // Sleep for SAP_INTERVAL_S, checking shutdown flag every second
        for (int i = 0; i < SAP_INTERVAL_S && m_sapRunning.load(); i++) {
            std::this_thread::sleep_for(std::chrono::seconds(1));
        }
    }

    // Send deletion packets on shutdown
    for (const auto& entry : entries) {
        sendto(sock, entry.deletePacket.data(), entry.deletePacket.size(), 0,
               (struct sockaddr*)&sapAddr, sizeof(sapAddr));
    }

    close(sock);
    LogInfo(VB_MEDIAOUT, "VideoOutputManager SAP announcer thread stopped (deletion packets sent)\n");
}
