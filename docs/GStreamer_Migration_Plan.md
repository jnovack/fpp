# GStreamer Migration Plan — Unified Media Clock Architecture

**Goal:** Replace both VLC and SDL media backends with GStreamer, providing a single clock source shared across PipeWire, AES67, and media playback — eliminating per-output delay discrepancies.

**Branch:** `gstreamer-migration`  
**Started:** 2025-02-14

---

## Key Technical Questions & Answers (dkulp review)

### Q1: Accelerated video playback on console (no X/Wayland)?
**Yes.** GStreamer's `kmssink` element (in plugins-bad, confirmed available on Pi) provides direct DRM/KMS console output — same approach VLC uses with `drm_vout`. On Pi 5, `v4l2h264dec` / `v4l2h265dec` provide hardware-accelerated decoding via V4L2 stateless API. No Beagle support needed (HDMI disabled on BBB).

### Q2: Speed control in tiny increments?
**Yes.** `gst_element_seek()` accepts a `gdouble rate` parameter (e.g., 0.98, 1.02). The rate change is applied via a seek event that can be non-flushing (no buffer drop). GStreamer's `pitch` element (plugins-bad) can adjust audio speed without pitch shift if desired. The existing VLC rate-matching algorithm (2% steps, trend detection, running average) can be ported directly — it's just math that calls `set_rate` vs `gst_element_seek`.

**Caveat:** Rate-change seeks in GStreamer can cause brief audio discontinuities depending on the pipeline. Needs testing with `pipewiresink` to verify smoothness. VLC has the same issue ("pops") — it's inherent to rate adjustment. A `scaletempo` element can help smooth audio during rate changes.

### Q3: Embedded API (not forking processes)?
**Yes.** GStreamer has a full C API (`libgstreamer-1.0`) with embedded pipeline construction, bus message handling, and state management — all in-process. No forking. Same pattern as libvlc. The API is stable (ABI-compatible since 1.0).

### Q4: Frame data extraction for matrix/overlay (without HDMI)?
**Yes.** `appsink` element provides decoded frame buffers directly in-process. Configure with `caps="video/x-raw,format=RGB"` and pull samples via `gst_app_sink_pull_sample()`. This replaces the current FFmpeg `sws_scale` → `PixelOverlayModel` path in SDLOut.cpp. GStreamer handles the colorspace conversion and scaling internally via `videoconvert` and `videoscale`.

### Q5: Multiple simultaneous media streams?
**Yes.** Each "Play Media" command creates an independent `GstElement* pipeline`. Multiple pipelines run concurrently, all sharing the PipeWire clock. The current `runningCommandMedia` map pattern works unchanged.

### Q6: Millisecond precision position reporting?
**Yes.** `gst_element_query_position(pipeline, GST_FORMAT_TIME, &pos)` returns position in nanoseconds. This is better than VLC's millisecond precision and far better than omxplayer's 1-second precision. The position query is non-blocking and can be called from the Process() loop.

### Q7: Buffer behavior during speed adjustment?
GStreamer speed changes are implemented as seek events. Options:
- **Flushing seek** (`GST_SEEK_FLAG_FLUSH`): Drops buffers, restarts from new rate — causes brief gap
- **Non-flushing seek**: Drains existing buffers at old rate, starts new buffers at new rate — smoother but slight delay before rate takes effect
- **`scaletempo` element**: Inserted in audio path, handles rate changes without pitch shift and minimizes glitches

The VLC code already tolerates small glitches during rate changes. GStreamer gives us more control over the tradeoff.

### Q8: Mixed-version ecosystem compatibility?
The MultiSync protocol is independent of the media backend. Master sends position updates, remotes adjust playback rate. Switching master to GStreamer doesn't affect remotes running VLC — they just see position timestamps. **Incremental migration is safe:** GStreamer master + VLC remotes, or vice versa. No minimum version required across the ecosystem.

**Strategy:** GStreamer on master first (no speed adjustment needed). VLC on remotes can remain until GStreamer rate matching is proven (Phase 5). This matches dkulp's suggestion.

---

## Architecture Overview

### Current State
```
                 ┌─── SDL2 (pulse driver) ───┐
Playlist/FSEQ ──►│   FFmpeg decode            │──► PipeWire (pulse compat) ──► Group ──► Filter-chains ──► ALSA/AES67
                 └────────────────────────────┘

                 ┌─── VLC (native PW plugin) ─┐
Play Media ─────►│   libvlc decode + output   │──► PipeWire (native) ──► Group ──► Filter-chains ──► ALSA/AES67
                 └────────────────────────────┘

Problem: Two different playback paths with different buffering = different latencies per output
```

### Target State
```
                 ┌─── GStreamer ──────────────────────────────┐
All playback ───►│   playbin / uridecodebin                  │
                 │   ├── audio: pipewiresink (shared clock)  │──► PipeWire ──► Group ──► Filter-chains ──► ALSA/AES67
                 │   └── video: appsink / kmssink            │──► PixelOverlay / HDMI
                 └───────────────────────────────────────────┘

Benefit: Single clock tree, consistent latency, one buffer path
```

---

## Phase Overview

| Phase | Scope                                                                    | Risk        | Status       |
| ----- | ------------------------------------------------------------------------ | ----------- | ------------ |
| **0** | Install GStreamer, build integration, proof of concept                   | Low         | **COMPLETE** |
| **1** | GStreamerOutput for "Play Media" command (replaces VLC in MediaCommands) | Low-Medium  | **COMPLETE** |
| **2** | GStreamerOutput for playlist/sequence audio (replaces SDL audio path)    | Medium      | **Complete** |
| **3** | Video-to-PixelOverlay via GStreamer appsink (replaces SDL+FFmpeg video)  | Medium-High | **Complete** |
| **4** | HDMI/DRM video output via GStreamer kmssink (replaces VLC video)         | High        | Not started  |
| **5** | MultiSync rate adjustment via GStreamer (replaces VLC AdjustSpeed)       | Medium-High | Not started  |
| **6** | Remove SDL and VLC dependencies entirely                                 | Low         | Not started  |
| **7** | AES67 via GStreamer (replaces PipeWire RTP modules)                      | Medium-High | Not started  |

### Migration Strategy (per dkulp)
> "We could always use gstreamer on master (no playback speed adjustments) and keep vlc for remotes if needed."

- **Phases 0-3** can deploy to master units immediately — no rate adjustment needed
- **Phase 5** (rate matching) only needed for remote/slave units in MultiSync
- VLC can remain as fallback on remotes via `MediaBackend` setting until Phase 5 is proven
- MultiSync protocol is backend-agnostic — mixed GStreamer/VLC ecosystems work

---

## Phase 0: Foundation — Install & Build Integration

**Objective:** Install GStreamer dev packages, create build system integration, verify `pipewiresink` plugin works with FPP's custom PipeWire runtime.

### Tasks

- [x] **0.1** Install GStreamer packages (GStreamer 1.26.2 installed):
  ```bash
  apt install libgstreamer1.0-dev libgstreamer-plugins-base1.0-dev \
              gstreamer1.0-plugins-base gstreamer1.0-plugins-good \
              gstreamer1.0-plugins-bad gstreamer1.0-pipewire \
              gstreamer1.0-plugins-ugly
  ```

- [x] **0.2** Verify `pipewiresink` element is available (confirmed: PipeWire 1.4.2, `target-object` property for sink routing):
  ```bash
  PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp gst-inspect-1.0 pipewiresink
  ```

- [x] **0.3** Proof of concept — play audio through PipeWire group sink via command line (confirmed: `pipewireclock0` used, audio played through all group members):
  ```bash
  PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp \
    gst-launch-1.0 filesrc location=/home/fpp/media/music/test.mp3 ! \
    decodebin ! audioconvert ! audioresample ! \
    pipewiresink target.object=fpp_group_stuart_headphones_and_hdmi
  ```

- [x] **0.4** Add GStreamer to build system in `src/makefiles/fpp_so.mk` (auto-detected via `/usr/include/gstreamer-1.0/gst/gst.h` wildcard):
  - Add `pkg-config --cflags gstreamer-1.0 gstreamer-app-1.0` to CFLAGS
  - Add `pkg-config --libs gstreamer-1.0 gstreamer-app-1.0` to LIBS
  - Add `mediaoutput/GStreamerOut.o` to object list

- [x] **0.5** Create `GStreamerOut.h` / `GStreamerOut.cpp` — full implementations, not just stubs:
  - Class `GStreamerOutput : public MediaOutputBase` with all virtual methods
  - `gst_init()` via `EnsureGStreamerInit()` with PipeWire env vars
  - `Start()`: builds `filesrc ! decodebin ! audioconvert ! audioresample ! volume ! pipewiresink` pipeline
  - `Stop()`, `Close()`: proper GstElement cleanup
  - `Process()`: bus message processing (EOS, error, state change) + position tracking
  - Looping via EOS → seek-to-start with loop counter
  - Volume via `volume` element (linear scale)
  - `SetVolumeAdjustment()` for per-media dB offset
  - Static stubs for `IsOverlayingVideo()`, `ProcessVideoOverlay()`, `GetAudioSamples()` (Phases 2-3)
  - `HAS_GSTREAMER` defined via `__has_include(<gst/gst.h>)`
  - **Successfully compiled and linked** — all symbols verified in `libfpp.so`

### Files Created
| File                               | Purpose           |
| ---------------------------------- | ----------------- |
| `src/mediaoutput/GStreamerOut.h`   | Class declaration |
| `src/mediaoutput/GStreamerOut.cpp` | Implementation    |

### Files Modified
| File                      | Change                           |
| ------------------------- | -------------------------------- |
| `src/makefiles/fpp_so.mk` | Add GStreamer libs + object file |

---

## Phase 1: "Play Media" Command via GStreamer

**Objective:** Replace VLC-based `PlayMediaCommand` / `StopMediaCommand` with GStreamer. This is the calibration playback path — fixing the "different delay per playback method" problem immediately.

### Current Code
- `src/commands/MediaCommands.cpp` lines 216-333: `VLCPlayData` class extends `VLCOutput`
- `PlayMediaCommand` creates a `VLCPlayData`, calls `Start()`, fires `MEDIA_STARTED` event
- `StopMediaCommand` looks up in `runningCommandMedia` map, calls `Stop()`
- Supports: looping (`input-repeat`), per-media volume adjustment (VLC EQ preamp)
- Event lifecycle: `Starting()` → `Playing()` → `Stopping()` → `Stopped()` (VLC callbacks)

### Target Design
```cpp
class GStreamerPlayData {
    GstElement* pipeline;     // playbin or manual pipeline
    GstElement* pipewiresink; // target: group combine-sink
    std::string filename;
    int loopCount;
    float volumeAdjust;       // via GStreamer "volume" element
    MediaOutputStatus status;
    
    // GStreamer bus watch replaces VLC event callbacks
    static gboolean busCallback(GstBus*, GstMessage*, gpointer);
};
```

### Tasks

- [x] **1.1** Implement `GStreamerOutput::Start()`:
  - Build pipeline: `filesrc ! decodebin ! audioconvert ! audioresample ! volume ! pipewiresink`
  - Set `PIPEWIRE_RUNTIME_DIR` env var (if not already set by SDL)
  - Target `pipewiresink` at default sink (the group combine-sink)
  - Set pipeline to `GST_STATE_PLAYING`

- [x] **1.2** Implement `GStreamerOutput::Stop()`:
  - Set pipeline to `GST_STATE_NULL`
  - Clean up references

- [x] **1.3** Implement `GStreamerOutput::Process()`:
  - Process GStreamer bus messages (EOS, errors, state changes)
  - Update `MediaOutputStatus` (elapsed time, position)

- [x] **1.4** Implement `GStreamerOutput::IsPlaying()`:
  - Query pipeline state

- [x] **1.5** Implement looping:
  - On EOS bus message, seek back to start if loop count > 0
  - Decrement loop counter

- [x] **1.6** Implement volume adjustment:
  - Use `volume` element in pipeline for per-media volume offset
  - Map the -100..+100 range to GStreamer volume scale

- [x] **1.7** Create `GStreamerPlayData` in `MediaCommands.cpp`:
  - Replaces `VLCPlayData` class (runtime selection via `UseGStreamerForPlayMedia()`)
  - Same lifecycle: register in `runningCommandMedia`, fire events on state changes
  - GStreamer bus sync handler for `GST_MESSAGE_EOS`, `GST_MESSAGE_ERROR`, `GST_MESSAGE_STATE_CHANGED`
  - `MediaOutputBase*` map allows both VLC and GStreamer instances to coexist

- [x] **1.8** Wire up `PlayMediaCommand` to use runtime backend selection:
  - `UseGStreamerForPlayMedia()` returns true when `AudioBackend=pipewire` or `MediaBackend=gstreamer`
  - Falls back to VLC when GStreamer not preferred or not available

- [x] **1.9** Test (all passing):
  - Play Media via API: confirmed "Play Media using GStreamer backend" in logs
  - GStreamer 1.26.2 initialized with PipeWire env vars
  - Audio played through PipeWire group sink (`pipewiresink target-object=fpp_group_...`)
  - Stop Media: confirmed clean stop
  - Stop All Media: confirmed
  - Looping: SetLoopCount with EOS→seek-to-start
  - MEDIA_STARTED/MEDIA_STOPPED events fired
  - No latency difference between outputs (shared PipeWire clock)

### Files Modified
| File                               | Change                                                    |
| ---------------------------------- | --------------------------------------------------------- |
| `src/mediaoutput/GStreamerOut.cpp` | Full audio playback implementation + BusSyncHandler       |
| `src/mediaoutput/GStreamerOut.h`   | BusSyncHandler declaration                                |
| `src/commands/MediaCommands.cpp`   | `GStreamerPlayData` + unified runtime backend selection   |
| `src/commands/MediaCommands.h`     | `#if defined(HAS_VLC)                                     |  | defined(HAS_GSTREAMER)` guards + GStreamerOut include |
| `src/commands/Commands.cpp`        | Command registration under both HAS_VLC and HAS_GSTREAMER |

### Validation
- [x] `amixer -c 0 sget Speaker` stays at 100% through playback
- [x] All group members produce audio simultaneously with matching latency
- [x] Bus errors logged cleanly on failure (no segfaults)
- [x] Runtime backend selection: GStreamer when `AudioBackend=pipewire`, VLC otherwise

---

## Phase 2: Playlist/Sequence Audio via GStreamer

**Objective:** Replace SDL audio path for the main playlist/sequence playback. This is the primary show path — fppd playing FSEQ files with synchronized audio.

### Current Code
- `src/mediaoutput/SDLOut.cpp` (1319 lines):
  - FFmpeg for decoding (`avformat`, `avcodec`, `swresample`)
  - Separate decode thread (`runDecode`) fills ring buffer
  - `SDL_QueueAudio()` pushes decoded PCM to SDL audio device
  - SDL audio driver set to "pulse" for PipeWire compatibility
  - `GetAudioSamples()` static method provides raw audio to WLED effects
  - `setMediaElapsed()` called during decode for position tracking
  - Handles seeking via buffer skip in `Start(msTime)`

### Target Design
```cpp
// GStreamer pipeline for sequence audio:
// filesrc ! decodebin ! audioconvert ! audioresample ! tee name=t
//   t. ! queue ! pipewiresink target.object=<group_sink>
//   t. ! queue ! appsink name=sampletap  (for WLED audio-reactive effects)
```

### Tasks

- [x] **2.1** Extend `GStreamerOutput` for sequence audio mode:
  - Accept FSEQ-paired audio file
  - Support `Start(int msTime)` for seeking to arbitrary position
  - Accurate position reporting via `gst_element_query_position()`

- [x] **2.2** Implement audio sample extraction for WLED:
  - Add `tee` + `appsink` branch to pipeline (F32LE mono, circular buffer)
  - Implement static `GetAudioSamples()` that reads from appsink buffer
  - Maintain API compatibility with existing `SDLOutput::GetAudioSamples(float*, int, int&)`
  - `wled.cpp` tries GStreamer first, falls back to SDL

- [x] **2.3** Update factory in `mediaoutput.cpp`:
  - `CreateMediaOutput()`: Audio-only → `GStreamerOutput` (replacing `SDLOutput`)
  - Keep SDL fallback initially behind a setting/compile flag

- [x] **2.4** Volume control integration:
  - `setVolume()` in `mediaoutput.cpp` continues using `pactl` for PipeWire system volume
  - GStreamer pipeline volume element used for per-stream adjustment if needed

- [x] **2.5** Test main show playback:
  - FSEQ with audio plays correctly through all group members
  - Seeking (start at non-zero position) works
  - Volume slider works (verified 30%→75% via pactl)
  - WLED audio-reactive effects receive samples (tee+appsink)
  - Position reporting accurate for sequence sync (elapsed/remaining)
  - Graceful stop/start between playlist entries (5x rapid cycle stress tested)
  - Pipeline teardown deadlock fixed (atomic shutdown flag + signal disconnect before state change)

### Files Modified
| File                               | Change                                                                   |
| ---------------------------------- | ------------------------------------------------------------------------ |
| `src/mediaoutput/GStreamerOut.cpp` | Extend for sequence audio mode, add appsink for WLED                     |
| `src/mediaoutput/GStreamerOut.h`   | Add static `GetAudioSamples()`                                           |
| `src/mediaoutput/mediaoutput.cpp`  | Factory: Audio-only → GStreamerOutput                                    |
| `src/overlays/wled/wled.cpp`       | Change `SDLOutput::GetAudioSamples` → `GStreamerOutput::GetAudioSamples` |

---

## Phase 3: Video-to-PixelOverlay via GStreamer

**Objective:** Replace SDL+FFmpeg video decoding that pushes frames to `PixelOverlayModel` for LED matrix output.

### Current Code
- `SDLOutput::ProcessVideoOverlay()` (line 901): Called from `Sequence.cpp` during frame processing
- Decodes video via FFmpeg, scales with `sws_scale`, pushes RGB data to overlay model
- `SDLOutput::IsOverlayingVideo()` (line 897): Gate for output thread

### Target Design
```cpp
// GStreamer video branch:
// ... decodebin ! videoconvert ! videoscale ! video/x-raw,format=RGB !
//     appsink name=videosink emit-signals=true
//
// GStreamerOutput::ProcessVideoOverlay() pulls frames from appsink
```

### Tasks

- [x] **3.1** Add video branch to GStreamer pipeline:
  - Dynamic pad linking via `decodebin` `pad-added` and `no-more-pads` signals
  - When `videoOut` is not `--Disabled--` or `--HDMI--`, creates video+audio pipeline:
    `filesrc ! decodebin name=decoder` with manually constructed audio and video sub-chains
  - Audio chain: `audioconvert ! audioresample ! tee name=t`
    - `t. ! queue ! volume ! pipewiresink` (playback)
    - `t. ! queue ! audioconvert ! capsfilter(F32LE,mono) ! appsink` (WLED sample tap)
  - Video chain: `queue ! videoconvert ! videoscale ! capsfilter(RGB,WxH) ! appsink(sync=true)`
  - `OnPadAdded()` matches decoded pads by media type (audio/video) and links them
  - `OnNoMorePads()` logs final audio/video link status
  - Video appsink sync=true ensures frames are paced to media clock
  - Appsink configured for RGB format at PixelOverlayModel resolution (e.g., 169x162)
  - `OnNewVideoSample()` callback handles stride padding (GStreamer pads RGB rows to 4-byte alignment: 169×3=507→508 bytes/row) — row-by-row copy strips padding

- [x] **3.2** Implement static `IsOverlayingVideo()` and `ProcessVideoOverlay()`:
  - Same API as SDLOutput versions
  - `IsOverlayingVideo()`: checks `m_currentInstance` has `m_videoOverlayModel` set
  - `ProcessVideoOverlay()`: copies latest video frame from `m_videoFrameData` buffer to `PixelOverlayModel::setData()`
  - Frame delivered via double buffering: `OnNewVideoSample` writes to `m_videoFrameData` under mutex, `ProcessVideoOverlay` reads+clears under same mutex
  - Overlay model disabled on first video frame, re-enabled on close (matches SDLOutput behavior)
  - `StartChannelOutputThread()` called when video overlay model is set (ensures `ProcessVideoOverlay` gets called from the channel output loop)
  - Diagnostic counters (`m_videoFramesReceived` / `m_videoFramesDelivered`) logged at frame 1, every 100 frames, and at close

- [x] **3.3** Update external callers:
  - `Sequence.cpp`: Added `GStreamerOutput::IsOverlayingVideo()` alongside SDLOutput check, calls both `ProcessVideoOverlay()` methods
  - `channeloutputthread.cpp`: Added `GStreamerOutput::IsOverlayingVideo()` to `forceOutput()` to keep channel output thread alive during video overlay
  - Both guarded with `#ifdef HAS_GSTREAMER`

- [x] **3.4** Update factory in `mediaoutput.cpp`:
  - Added: `if (useGStreamer && IsExtensionVideo(ext) && !IsHDMIOut(vo))` creates `GStreamerOutput` with overlay videoOut
  - HDMI output still routes to VLC/SDL (Phase 4)

- [x] **3.5** Fix PlayMediaCommand args safety:
  - `MediaCommands.cpp`: Changed `int loop = std::atoi(args[1].c_str())` to safely check `args.size() > 1` with default 1

### Validation
- [x] Video-only (Matrix-1.mp4, 45s): 3005 frames received, 900 delivered, EOS at 45s, clean shutdown
- [x] Audio+video (Carol of the Bells, 2:42): Both pads linked, video frames received/delivered, audio heard through Sound Blaster
- [x] Stride padding: 508-byte rows (padded) → 507-byte rows (tightly packed) → 82134 bytes per frame
- [x] Channel output thread: `StartChannelOutputThread()` ensures ProcessVideoOverlay is called
- [x] PixelOverlayModel disable/re-enable lifecycle matches SDLOutput behavior

### Files Modified
| File                                        | Change                                                              |
| ------------------------------------------- | ------------------------------------------------------------------- |
| `src/mediaoutput/GStreamerOut.cpp`          | Video appsink branch, pad linking, stride fix, diagnostic logging   |
| `src/mediaoutput/GStreamerOut.h`            | Video members, PixelOverlayModel, pad linking, frame counters       |
| `src/mediaoutput/mediaoutput.cpp`           | Factory: Video+overlay → GStreamerOutput                            |
| `src/Sequence.cpp`                          | Add GStreamerOutput::IsOverlayingVideo/ProcessVideoOverlay calls    |
| `src/channeloutput/channeloutputthread.cpp` | Add GStreamerOutput::IsOverlayingVideo to forceOutput()             |
| `src/commands/MediaCommands.cpp`            | Args bounds check fix                                               |

---

## PipeWire Audio Routing Stability Fixes

**Status: COMPLETE** ✅

**Problem:** ALSA card numbers are unstable — adding/removing USB audio devices reassigns card numbers, breaking PipeWire configs that reference cards by number (`hw:0`). Also, duplicate device names (e.g., two identical USB cards) caused UI display collisions and incorrect card addressing.

### Tasks Completed
1. **FPPINIT stable ALSA card IDs:** Changed `setupAudio()` to use stable ALSA card IDs (e.g., `hw:S3`) instead of card numbers (`hw:0`) in `95-fpp-alsa-sink.conf`. Added `getAlsaCardId()` helper that resolves card number → stable ID via `/proc/asound/cards`.
2. **Audio groups card→sink resolution:** Replaced fragile three-strategy card resolution in `ApplyPipeWireAudioGroups()` with `pw-dump` JSON parsing. Now uses `alsa.card` property (WirePlumber sinks) and `api.alsa.path` resolution (FPP-created sinks) for definitive card-to-PipeWire-sink mapping.
3. **Duplicate card name disambiguation in UI:** Both the AudioOutput options dropdown (`options.php`) and PipeWire Primary Output dropdown (`settings-av.php`) now detect duplicate card names and append `[ALSA_ID]` suffix for disambiguation (e.g., "ICUSBAUDIO7D [ICUSBAUDIO7D_1]").

### Files Modified
| File                                         | Change                                                              |
| -------------------------------------------- | ------------------------------------------------------------------- |
| `src/boot/FPPINIT.cpp`                      | `getAlsaCardId()` helper; `hw:N` → `hw:ID` in sink config          |
| `www/api/controllers/pipewire.php`           | pw-dump card resolution replacing by-path/fpp_card/pattern matching |
| `www/api/controllers/options.php`            | Duplicate card name detection + `[cardId]` disambiguation           |
| `www/settings-av.php`                        | Duplicate card name detection for PipeWire dropdown                 |

---

## Phase 4: HDMI/DRM Video via GStreamer ✅

**Objective:** Replace VLC's DRM/KMS video output with GStreamer's `kmssink` for direct HDMI playback.

**Status:** Complete — tested 2026-02-17 on Pi 5/Bookworm. Video plays full-screen on HDMI with audio through PipeWire.

### Implementation

#### DRM Connector Resolution (`ResolveDrmConnector()`)
- Scans `/sys/class/drm/cardN-<connector>/` for connector_id, status, modes
- Cross-Pi compatible: iterates cards 0-7, works regardless of which card is the display card
- Pi 5: card0=v3d (render), card1=vc4-drm (display). Pi 4: varies by kernel
- Returns `DrmConnectorInfo` struct with cardPath, connectorId, connected flag, display width/height

#### HDMI Video Pipeline
```
filesrc ! decodebin name=decoder
  decoder.video → queue ! videoconvert ! videoscale ! capsfilter(WxH) ! kmssink
  decoder.audio → audioconvert ! audioresample ! tee
                    ├→ queue ! pipewiresink (main audio)
                    └→ queue ! appsink (WLED tap)
```
- `kmssink` properties: `driver-name=vc4`, `connector-id=N`, `skip-vsync=TRUE`, `restore-crtc=TRUE`
- Video scaled to display resolution via `videoscale ! capsfilter` to fill screen
- `decodebin` auto-selects best decoder by rank (no platform-specific decode code)
  - Pi 5: `v4l2slh265dec` for H.265, `avdec_h264` (software) for H.264
  - Pi 4: `v4l2h264dec` if available, else `avdec_h264`
  - Universal: `avdec_h264` from `gstreamer1.0-libav` (always available)

#### Video Output Routing
- `IsHDMIOut()` recognizes connector names: `HDMI-*`, `DSI-*`, `Composite-*`, `--HDMI--`
- `CreateMediaOutput()` factory: `useGStreamer && IsExtensionVideo && IsHDMIOut` → `GStreamerOutput`

### Files Modified
| File                               | Change                                                              |
| ---------------------------------- | ------------------------------------------------------------------- |
| `src/mediaoutput/GStreamerOut.h`   | `DrmConnectorInfo` struct, `ResolveDrmConnector()`, HDMI members    |
| `src/mediaoutput/GStreamerOut.cpp` | `ResolveDrmConnector()`, HDMI pipeline in `Start()`, cleanup paths  |
| `src/mediaoutput/mediaoutput.cpp`  | Factory: Video+HDMI → `GStreamerOutput` (before VLC fallback)       |

---

## Phase 5: MultiSync Rate Adjustment

**Objective:** Implement remote/multisync speed matching equivalent to VLC's `AdjustSpeed()`.

### Current Code
- `VLCOut.cpp` lines 553-700: ~150 lines of rate matching logic
- Circular buffer of position diffs, trend detection, proportional rate adjustment
- Rate clamped [0.5, 2.0], seek jumps for >10s drift
- SDLOutput does NOT implement speed adjustment (no-op)

### Target Design
```cpp
// GStreamer rate adjustment:
// gst_element_seek(pipeline, rate, GST_FORMAT_TIME,
//     GST_SEEK_FLAG_FLUSH | GST_SEEK_FLAG_ACCURATE,
//     GST_SEEK_TYPE_NONE, 0, GST_SEEK_TYPE_NONE, 0);
//
// Port the existing trend detection / proportional control from VLCOut
```

### Tasks

- [ ] **5.1** Implement `GStreamerOutput::AdjustSpeed(float masterPos)`:
  - Port rate-matching algorithm from VLCOut.cpp
  - Use GStreamer seek with rate parameter
  - Same trend detection, circular buffer, proportional control

- [ ] **5.2** Test in remote/multisync mode:
  - Slave FPP instance syncs audio to master
  - Rate adjustments smooth (no glitches)
  - Large drifts handled with seek jumps

### Files Modified
| File                               | Change                                         |
| ---------------------------------- | ---------------------------------------------- |
| `src/mediaoutput/GStreamerOut.cpp` | Implement AdjustSpeed with rate-matching logic |

---

## Phase 6: Remove SDL and VLC Dependencies

**Objective:** Clean removal of all SDL2, VLC, and FFmpeg dependencies once GStreamer handles everything.

### Tasks

- [ ] **6.1** Remove source files:
  - `src/mediaoutput/VLCOut.cpp` / `VLCOut.h`
  - `src/mediaoutput/SDLOut.cpp` / `SDLOut.h`

- [ ] **6.2** Update build system:
  - Remove from `src/makefiles/fpp_so.mk`: `-lSDL2 -lvlc -lavformat -lavcodec -lavutil -lswresample -lswscale`
  - Remove VLC conditional detection block
  - Remove SDL/VLC object files from build

- [ ] **6.3** Clean up includes and references:
  - `src/mediaoutput/mediaoutput.cpp`: Remove SDL/VLC includes, simplify factory
  - `src/commands/MediaCommands.cpp`: Remove `#ifdef HAS_VLC` guards
  - Remove `HAS_VLC` compile flag entirely

- [ ] **6.4** Update install scripts:
  - `SD/FPP_Install.sh`: Add GStreamer packages, optionally remove SDL2/VLC dev packages
  - Ensure runtime GStreamer plugins are installed (gst-plugins-good, -bad, -pipewire)

- [ ] **6.5** Update `VLCOptions` setting handling:
  - Migrate or deprecate the `VLCOptions` freeform setting
  - Add equivalent `GStreamerOptions` if needed

- [ ] **6.6** Backward compatibility:
  - Ensure ALSA-only mode (no PipeWire) still works with GStreamer `alsasink`
  - Test on all platforms: Pi 4, Pi 5, BBB, x86

### Files Removed
| File                         |
| ---------------------------- |
| `src/mediaoutput/VLCOut.cpp` |
| `src/mediaoutput/VLCOut.h`   |
| `src/mediaoutput/SDLOut.cpp` |
| `src/mediaoutput/SDLOut.h`   |

### Files Modified
| File                              | Change                             |
| --------------------------------- | ---------------------------------- |
| `src/makefiles/fpp_so.mk`         | Remove SDL/VLC/FFmpeg libs         |
| `src/mediaoutput/mediaoutput.cpp` | Simplify to GStreamer-only factory |
| `src/commands/MediaCommands.cpp`  | Remove HAS_VLC guards              |
| `SD/FPP_Install.sh`               | Update package dependencies        |

---

## Phase 7: AES67 via GStreamer

**Objective:** Replace PipeWire's `libpipewire-module-rtp-sink` / `module-rtp-source` with GStreamer-based AES67 send/receive pipelines that use GStreamer's native `GstPtpClock` for IEEE 1588 PTP-derived media clock timestamps — achieving true AES67 compliance.

### Why GStreamer is Better for AES67

The current PipeWire approach has several limitations:

| Aspect                   | PipeWire RTP Modules                                                                                      | GStreamer RTP                                                                            |
| ------------------------ | --------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| **PTP clock**            | Indirect — `ptp4l` disciplines system clock, PipeWire uses `CLOCK_MONOTONIC`                              | Native `GstPtpClock` — RTP timestamps derive directly from IEEE 1588 PTP time            |
| **Media clock**          | Not AES67-compliant — no `mediaclk:direct=0` support                                                      | `rtpbin.rfc7273-sync=true` — purpose-built for AES67 media clock reconstruction          |
| **SAP/SDP**              | Non-compliant — missing `ts-refclk`, wrong `mediaclk`, wrong direction. Requires custom Python SAP daemon | GStreamer SDP library can generate compliant SDP with correct `ts-refclk` and `mediaclk` |
| **Packet timing**        | Limited control over ptime                                                                                | `rtpL24pay` has `min-ptime` / `max-ptime` for strict 1ms/4ms AES67 intervals             |
| **Pipeline integration** | Audio must route through PipeWire combine-stream → adds hop                                               | `tee` in GStreamer pipeline → zero-copy branch to both pipewiresink and RTP              |
| **Receive sync**         | Jitter buffer only, no PTP-aware sync                                                                     | `rtpbin.rfc7273-sync=true` + `GstPtpClock` for PTP-aware playout                         |
| **L24 codec**            | Handled internally by module                                                                              | Native `rtpL24pay` / `rtpL24depay` — RFC 3190 compliant                                  |
| **Error recovery**       | Module restart required                                                                                   | Pipeline can be rebuilt/restarted independently                                          |

### Current AES67 Architecture (PipeWire)
```
Config: pipewire-aes67-instances.json
  │
  ├── apply_aes67_config (Python)
  │   ├── Writes: 96-fpp-aes67-rtp.conf (PipeWire modules)
  │   ├── Writes: 96-fpp-aes67-sap.conf (SAP receive)
  │   ├── Writes: /etc/ptp4l-fpp.conf
  │   ├── Starts: ptp4l daemon
  │   └── Starts: fpp_aes67_sap daemon (custom SAP announcer)
  │
  └── PipeWire runtime:
      ├── libpipewire-module-rtp-sink  → multicast RTP out
      ├── libpipewire-module-rtp-source → multicast RTP in
      └── libpipewire-module-rtp-sap   → SAP receive
```

### Target AES67 Architecture (GStreamer)
```
Config: pipewire-aes67-instances.json (same JSON format)
  │
  ├── AES67Manager (C++, in fppd)
  │   ├── For each SEND instance:
  │   │   GstPipeline:
  │   │     pipewiresrc (captures from group/node) → audioconvert →
  │   │     rtpL24pay pt=96 → rtpbin (PTP clock) → udpsink (multicast)
  │   │
  │   ├── For each RECEIVE instance:
  │   │   GstPipeline:
  │   │     udpsrc (multicast) → rtpbin (rfc7273-sync, PTP clock) →
  │   │     rtpL24depay → audioconvert → pipewiresink (creates virtual source)
  │   │
  │   ├── PTP: gst_ptp_init() + GstPtpClock per domain
  │   │   (replaces external ptp4l daemon)
  │   │
  │   └── SAP: Built-in compliant SAP announcer thread
  │       (replaces fpp_aes67_sap Python daemon)
  │
  └── Still integrates with PipeWire Audio Groups:
      ├── Send: pipewiresrc captures from group combine-sink (or specific node)
      └── Receive: pipewiresink creates virtual Audio/Source node
```

### Available GStreamer Elements (verified on Pi 5)
```
rtpL24pay / rtpL24depay  — L24 (S24BE) payload, RFC 3190
rtpbin                   — RTP session manager, rfc7273-sync, NTP sync
udpsrc / udpsink         — UDP multicast send/receive
multiudpsink             — Multi-destination UDP (SAP multicast)
GstPtpClock              — IEEE 1588 PTP clock (gst_ptp_clock_new)
pipewiresrc              — Capture from PipeWire node
pipewiresink             — Output to PipeWire node
```

### Tasks

- [ ] **7.1** Create `AES67Manager` class:
  - Singleton lifecycle managed by fppd
  - Reads `pipewire-aes67-instances.json` config
  - Creates/destroys GStreamer pipelines per instance
  - Manages `gst_ptp_init()` / `GstPtpClock` lifecycle

- [ ] **7.2** Implement AES67 send pipeline:
  - Pipeline: `pipewiresrc target-object=<node> ! audioconvert ! audio/x-raw,format=S24BE,rate=48000 ! rtpL24pay pt=96 min-ptime=<ptime_ns> max-ptime=<ptime_ns> ! rtpbin ! udpsink host=<multicast_ip> port=<port> multicast-iface=<iface> ttl=4 auto-multicast=true`
  - Use `GstPtpClock` as pipeline clock for PTP-derived RTP timestamps
  - Support 1ms and 4ms packet times per AES67 spec
  - Support 2-8 channels with correct channel position mapping

- [ ] **7.3** Implement AES67 receive pipeline:
  - Pipeline: `udpsrc multicast-group=<ip> port=<port> ! application/x-rtp,media=audio,clock-rate=48000,encoding-name=L24 ! rtpbin rfc7273-sync=true ! rtpL24depay ! audioconvert ! pipewiresink`
  - Use PTP clock for receive-side media clock reconstruction
  - Create a PipeWire `Audio/Source` node that routes into the local graph
  - Configurable jitter buffer latency

- [ ] **7.4** Implement SAP announcer (replaces fpp_aes67_sap Python daemon):
  - Build AES67-compliant SDP with:
    - `a=ts-refclk:ptp=IEEE1588-2008:<gmClockId>:0`
    - `a=mediaclk:direct=0 rate=48000`
    - `a=sendonly` / `a=recvonly`
    - `a=rtpmap:96 L24/48000/<channels>`
  - Send RFC 2974 SAP packets to `239.255.255.255:9875`
  - Send deletion packets on instance removal/shutdown
  - Could use GStreamer's SDP library (`GstSDPMessage`) for SDP construction

- [ ] **7.5** Implement SAP receiver (replaces PipeWire `module-rtp-sap`):
  - Listen on `239.255.255.255:9875` for SAP announcements
  - Parse SDP, auto-create receive pipelines for discovered streams
  - Handle stream removal via SAP deletion packets

- [ ] **7.6** Integrate `GstPtpClock` (replaces external ptp4l daemon):
  - Call `gst_ptp_init(GST_PTP_CLOCK_ID_NONE, NULL)` at startup
  - Create `GstPtpClock` for domain 0 (default AES67 domain)
  - Participate in BMCA (Best Master Clock Algorithm)
  - Expose PTP status (synced/offset/GM identity) via API
  - **Fallback:** Keep `ptp4l` as optional external clock source if GStreamer PTP has limitations

- [ ] **7.7** Update PHP API (`pipewire.php`):
  - `POST /api/pipewire/aes67/apply` → signals fppd to rebuild GStreamer AES67 pipelines instead of writing PipeWire module configs
  - AES67 status endpoint reads from GStreamer pipeline state + PTP statistics
  - Config format unchanged (backward-compatible JSON)

- [ ] **7.8** Update boot sequence (`FPPINIT.cpp`):
  - Remove PipeWire RTP module config generation
  - Remove `ptp4l` daemon startup (if GStreamer PTP used)
  - Remove `fpp_aes67_sap` daemon startup
  - Signal fppd to initialize AES67Manager at startup

- [ ] **7.9** Direct media-to-AES67 path (zero-hop optimization):
  - When playing media that should go to AES67, add an RTP branch directly in the GStreamer media pipeline via `tee`:
    ```
    decodebin ! audioconvert ! tee
      ├── pipewiresink (local playback)
      └── audioconvert ! rtpL24pay ! rtpbin ! udpsink (AES67)
    ```
  - Eliminates the PipeWire combine-stream → pipewiresrc → GStreamer RTP chain
  - Lower latency, fewer clock domain crossings
  - Only possible when GStreamer is the media backend (Phase 2+)

- [ ] **7.10** Test with AES67 ecosystem:
  - Send: Verify RTP stream received by AES67 Stream Monitor / Dante receivers
  - Receive: Verify FPP receives streams from Dante / other AES67 senders
  - SAP: Verify discovery in AES67 Stream Monitor and Dante Controller
  - PTP: Verify clock sync accuracy (sub-millisecond)
  - Multi-instance: Multiple send + receive simultaneously
  - Migration: Verify existing `pipewire-aes67-instances.json` configs work without changes

### Files Created
| File                               | Purpose                                            |
| ---------------------------------- | -------------------------------------------------- |
| `src/mediaoutput/AES67Manager.h`   | AES67 pipeline manager class                       |
| `src/mediaoutput/AES67Manager.cpp` | Send/receive pipeline construction, PTP clock, SAP |

### Files Modified
| File                               | Change                                                 |
| ---------------------------------- | ------------------------------------------------------ |
| `src/mediaoutput/GStreamerOut.cpp` | Add optional RTP tee branch for direct AES67           |
| `www/api/controllers/pipewire.php` | Update apply endpoint to signal fppd                   |
| `src/boot/FPPINIT.cpp`             | Remove PipeWire RTP module/ptp4l/SAP daemon startup    |
| `src/makefiles/fpp_so.mk`          | Add `AES67Manager.o`, add `-lgstnet-1.0` for PTP clock |

### Files Eventually Removed
| File                          | Replaced By                    |
| ----------------------------- | ------------------------------ |
| `scripts/apply_aes67_config`  | `AES67Manager` in fppd         |
| `scripts/fpp_aes67_sap`       | Built-in SAP in `AES67Manager` |
| `scripts/fpp_aes67_common.py` | Constants/logic moved to C++   |

### Migration Strategy
- **Phase 7 does NOT block Phases 2-6** — PipeWire RTP modules continue working
- Can run in parallel: GStreamer for media playback + PipeWire for AES67 initially
- Migration: Replace PipeWire RTP modules one instance at a time
- Fallback: Keep `apply_aes67_config` script as alternative if GStreamer PTP has issues
- The `pipewire-aes67-instances.json` config format stays the same — only the backend changes

---

## Dependencies

### Packages Required
```
libgstreamer1.0-dev          # Core GStreamer development
libgstreamer-plugins-base1.0-dev  # Base plugins development (appsink, etc.)
libgstreamer-plugins-bad1.0-dev   # Bad plugins development (rtpsink/rtpsrc)
gstreamer1.0-plugins-base    # Runtime: audioconvert, audioresample, playback
gstreamer1.0-plugins-good    # Runtime: autoaudiosink, rtpL24pay/depay, udpsrc/sink, rtpbin
gstreamer1.0-plugins-bad     # Runtime: kmssink (for HDMI video), rtpsink/rtpsrc
gstreamer1.0-plugins-ugly    # Runtime: mp3 decoding (mpg123), etc.
gstreamer1.0-pipewire        # Runtime: pipewiresink/pipewiresrc elements
# libgstnet-1.0 (from libgstreamer1.0-dev) provides GstPtpClock for AES67
```

### Packages Eventually Removed
```
libsdl2-dev                  # After Phase 6
libvlc-dev                   # After Phase 6
# FFmpeg libs may be removed if GStreamer handles all decoding internally
# But may keep if other parts of FPP use them directly
```

---

## Risk Mitigation

1. **Compile flags:** Keep `HAS_VLC` / `HAS_SDL` guards so old code can be re-enabled during development
2. **Setting:** Add `MediaBackend=gstreamer|vlc|sdl` setting for runtime switching during transition
3. **Each phase is independently testable** — no phase depends on later phases being complete
4. **Fallback:** If GStreamer doesn't work on a platform, the factory can fall back to SDL/VLC

---

## Clock Architecture Detail

The key architectural benefit:

```
PipeWire clock (CLOCK_MONOTONIC)
├── ALSA sink (card 0): driven by PipeWire graph clock
├── ALSA sink (card 1): driven by PipeWire graph clock  
├── AES67 RTP sink: driven by PipeWire graph clock + PTP
├── Filter-chain delay nodes: inline in PipeWire graph
└── GStreamer pipewiresink: JOINS the PipeWire graph clock
    └── Media decode happens upstream, but audio delivery
        is paced by PipeWire's pull-based scheduling

Result: All outputs are clocked from the same source.
        Delay filter-chains operate identically regardless
        of whether audio came from playlist or "Play Media".
```

With SDL (current): SDL_QueueAudio pushes into PulseAudio compat layer → extra buffer → different timing.
With VLC native PipeWire: VLC has its own internal buffering → different timing.
With GStreamer pipewiresink: GStreamer becomes a node in the PipeWire graph → same timing as everything else.

### AES67 Clock Architecture (Phase 7)

```
Current PipeWire AES67 approach:
  ptp4l (external daemon) ──disciplines──► system CLOCK_MONOTONIC
  PipeWire graph clock (CLOCK_MONOTONIC) ──► module-rtp-sink ──► RTP multicast
  Problem: PTP sync is indirect — system clock discipline has jitter,
           PipeWire RTP timestamps ≠ PTP media clock timestamps.
           Custom Python SAP daemon needed (PipeWire SAP is non-compliant).

Target GStreamer AES67 approach:
  GstPtpClock (domain 0) ──► Pipeline clock
  ├── rtpL24pay timestamps derive directly from PTP time
  ├── rtpbin.rfc7273-sync=true for receive-side PTP reconstruction
  └── SDP with correct ts-refclk/mediaclk attributes

  Media ──► decodebin ──► audioconvert ──► tee
              ├── pipewiresink (local playback, same PipeWire graph clock)
              └── rtpL24pay ──► rtpbin ──► udpsink (AES67 multicast)
                  clocked by GstPtpClock = true AES67 media clock

  Benefit: RTP timestamps are PTP-derived (not system-clock-derived),
           meeting AES67's media clock requirement exactly.
           SAP announcements can include correct ts-refclk.
```
