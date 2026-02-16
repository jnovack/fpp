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

    // Static methods matching SDLOutput interface — for later phases
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
    GstElement* m_appsink = nullptr;
    GstBus* m_bus = nullptr;
    std::string m_videoOut;
    int m_loopCount = 0;
    int m_volumeAdjust = 0;
    bool m_playing = false;
    std::atomic<bool> m_shutdownFlag{false};  // guards OnNewSample during teardown
    gulong m_appsinkSignalId = 0;            // signal handler ID for disconnection

    // Stall watchdog — detects when PipeWire sink stops consuming data
    gint64 m_lastPosition = -1;
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

    static GStreamerOutput* m_currentInstance;
};

#endif
