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
    GstBus* m_bus = nullptr;
    std::string m_videoOut;
    int m_loopCount = 0;
    int m_volumeAdjust = 0;
    bool m_playing = false;

    void ProcessMessages();
    static GstBusSyncReply BusSyncHandler(GstBus* bus, GstMessage* msg, gpointer userData);

    static GStreamerOutput* m_currentInstance;
};

#endif
