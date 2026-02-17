#pragma once
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

#include "MediaOutputBase.h"

#if __has_include(<gst/gst.h>)
#define HAS_GSTREAMER

#include <gst/gst.h>
#include <gst/app/gstappsink.h>
#include <array>
#include <atomic>
#include <mutex>
#include <vector>

#include "AES67Manager.h"

class PixelOverlayModel;

class GStreamerOutput : public MediaOutputBase {
public:
    GStreamerOutput(const std::string& mediaFilename, MediaOutputStatus* status, const std::string& videoOut = "");
    virtual ~GStreamerOutput();

    virtual int Start(int msTime = 0) override;
    virtual int Stop(void) override;
    virtual int Process(void) override;
    virtual int Close(void) override;
    virtual int IsPlaying(void) override;
    virtual int AdjustSpeed(float masterPos) override;
    virtual void SetVolume(int volume) override;

    // Static methods matching SDLOutput interface
    static bool IsOverlayingVideo();
    static bool ProcessVideoOverlay(unsigned int msTimestamp);
    static bool GetAudioSamples(float* samples, int numSamples, int& sampleRate);

    // GStreamer-specific
    void SetLoopCount(int loops) { m_loopCount = loops; }
    void SetVolumeAdjustment(int volAdj);

    // Event callbacks (matching VLCOutput pattern)
    virtual void Starting() {}
    virtual void Playing() {}
    virtual void Stopping() {}
    virtual void Stopped() {}

private:
    GstElement* m_pipeline = nullptr;
    GstElement* m_volume = nullptr;
    GstElement* m_appsink = nullptr;       // audio sample tap
    GstElement* m_videoAppsink = nullptr;   // video frame sink for PixelOverlay
    GstBus* m_bus = nullptr;
    std::string m_videoOut;
    int m_loopCount = 0;
    int m_volumeAdjust = 0;
    std::atomic<bool> m_playing{false};     // written from GStreamer thread (BusSyncHandler), read from main loop
    std::atomic<bool> m_shutdownFlag{false};  // guards callbacks during teardown
    gulong m_appsinkSignalId = 0;            // audio appsink signal handler ID
    gulong m_videoAppsinkSignalId = 0;       // video appsink signal handler ID

    // Stall watchdog — detects when PipeWire sink stops consuming data
    gint64 m_lastPosition = -1;
    gint64 m_maxDuration = 0;   // highest observed duration (handles VBR fluctuations)
    uint64_t m_stallStartMs = 0;
    static constexpr int STALL_TIMEOUT_MS = 5000; // 5 seconds before declaring stall

    void ProcessMessages();
    static GstBusSyncReply BusSyncHandler(GstBus* bus, GstMessage* msg, gpointer userData);

    // Audio sample tap for WLED audio-reactive effects
    static constexpr int SAMPLE_BUFFER_SIZE = 4096; // circular buffer size
    static std::array<float, SAMPLE_BUFFER_SIZE> s_sampleBuffer;
    static int s_sampleWritePos;
    static int s_sampleRate;
    static std::mutex s_sampleMutex;
    static GstFlowReturn OnNewSample(GstAppSink* appsink, gpointer userData);

    // Video overlay for PixelOverlayModel (Phase 3)
    PixelOverlayModel* m_videoOverlayModel = nullptr;
    std::mutex m_videoOverlayModelLock;
    int m_videoOverlayWidth = 0;
    int m_videoOverlayHeight = 0;
    bool m_hasVideoStream = false;
    bool m_wasOverlayDisabled = false;

    // Latest video frame buffer — written by OnNewVideoSample, read by ProcessVideoOverlay
    std::vector<uint8_t> m_videoFrameData;
    std::mutex m_videoFrameMutex;
    bool m_videoFrameReady = false;
    uint64_t m_videoFramesReceived = 0;    // diagnostic counter
    uint64_t m_videoFramesDelivered = 0;   // diagnostic counter

    static GstFlowReturn OnNewVideoSample(GstAppSink* appsink, gpointer userData);

    // HDMI/DRM video output via kmssink (Phase 4)
    bool m_wantHDMI = false;               // true when outputting to HDMI via kmssink
    GstElement* m_kmssink = nullptr;       // kmssink element (owned by pipeline bin)
    int m_hdmiConnectorId = -1;            // DRM connector ID from sysfs
    std::string m_hdmiCardPath;            // e.g. "/dev/dri/card1"
    int m_hdmiDisplayWidth = 0;            // display resolution
    int m_hdmiDisplayHeight = 0;

    // Resolve connector name (e.g., "HDMI-A-1") to DRM card path, connector ID,
    // and display resolution by scanning sysfs.  Works on all Pi models.
    struct DrmConnectorInfo {
        std::string cardPath;     // e.g. "/dev/dri/card1"
        int connectorId = -1;    // integer ID for kmssink
        bool connected = false;
        int displayWidth = 0;
        int displayHeight = 0;
    };
    static DrmConnectorInfo ResolveDrmConnector(const std::string& connectorName);

    // Dynamic pad linking for decodebin (video requires pad-added signal)
    static void OnPadAdded(GstElement* element, GstPad* pad, gpointer userData);
    static void OnNoMorePads(GstElement* element, gpointer userData);
    GstElement* m_audioChain = nullptr;    // audio sub-bin for pad linking
    GstElement* m_videoChain = nullptr;    // video sub-bin for pad linking
    bool m_audioLinked = false;            // true when audio pad was connected
    bool m_videoLinked = false;            // true when video pad was connected

#ifdef HAS_AES67_GSTREAMER
    // Zero-hop AES67 RTP branches attached to the audio tee (Phase 7.9)
    std::vector<AES67Manager::InlineRTPBranch> m_aes67Branches;
    void AttachAES67Branches(GstElement* tee);
    void DetachAES67Branches();
#endif

    static GStreamerOutput* m_currentInstance;
};

#endif
