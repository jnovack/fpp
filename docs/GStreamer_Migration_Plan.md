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

| Phase | Scope | Risk | Status |
|-------|-------|------|--------|
| **0** | Install GStreamer, build integration, proof of concept | Low | **COMPLETE** |
| **1** | GStreamerOutput for "Play Media" command (replaces VLC in MediaCommands) | Low-Medium | **COMPLETE** |
| **2** | GStreamerOutput for playlist/sequence audio (replaces SDL audio path) | Medium | Not started |
| **3** | Video-to-PixelOverlay via GStreamer appsink (replaces SDL+FFmpeg video) | Medium-High | Not started |
| **4** | HDMI/DRM video output via GStreamer kmssink (replaces VLC video) | High | Not started |
| **5** | MultiSync rate adjustment via GStreamer (replaces VLC AdjustSpeed) | Medium-High | Not started |
| **6** | Remove SDL and VLC dependencies entirely | Low | Not started |

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
| File | Purpose |
|------|---------|
| `src/mediaoutput/GStreamerOut.h` | Class declaration |
| `src/mediaoutput/GStreamerOut.cpp` | Implementation |

### Files Modified
| File | Change |
|------|--------|
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
| File | Change |
|------|--------|
| `src/mediaoutput/GStreamerOut.cpp` | Full audio playback implementation + BusSyncHandler |
| `src/mediaoutput/GStreamerOut.h` | BusSyncHandler declaration |
| `src/commands/MediaCommands.cpp` | `GStreamerPlayData` + unified runtime backend selection |
| `src/commands/MediaCommands.h` | `#if defined(HAS_VLC) || defined(HAS_GSTREAMER)` guards + GStreamerOut include |
| `src/commands/Commands.cpp` | Command registration under both HAS_VLC and HAS_GSTREAMER |

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

- [ ] **2.1** Extend `GStreamerOutput` for sequence audio mode:
  - Accept FSEQ-paired audio file
  - Support `Start(int msTime)` for seeking to arbitrary position
  - Accurate position reporting via `gst_element_query_position()`

- [ ] **2.2** Implement audio sample extraction for WLED:
  - Add `tee` + `appsink` branch to pipeline
  - Implement static `GetAudioSamples()` that reads from appsink buffer
  - Maintain API compatibility with existing `SDLOutput::GetAudioSamples(float*, int, int&)`

- [ ] **2.3** Update factory in `mediaoutput.cpp`:
  - `CreateMediaOutput()`: Audio-only → `GStreamerOutput` (replacing `SDLOutput`)
  - Keep SDL fallback initially behind a setting/compile flag

- [ ] **2.4** Volume control integration:
  - `setVolume()` in `mediaoutput.cpp` continues using `pactl` for PipeWire system volume
  - GStreamer pipeline volume element used for per-stream adjustment if needed

- [ ] **2.5** Test main show playback:
  - FSEQ with audio plays correctly through all group members
  - Seeking (start at non-zero position) works
  - Volume slider works
  - WLED audio-reactive effects receive samples
  - Position reporting accurate for sequence sync
  - Graceful stop/start between playlist entries

### Files Modified
| File | Change |
|------|--------|
| `src/mediaoutput/GStreamerOut.cpp` | Extend for sequence audio mode, add appsink for WLED |
| `src/mediaoutput/GStreamerOut.h` | Add static `GetAudioSamples()` |
| `src/mediaoutput/mediaoutput.cpp` | Factory: Audio-only → GStreamerOutput |
| `src/overlays/wled/wled.cpp` | Change `SDLOutput::GetAudioSamples` → `GStreamerOutput::GetAudioSamples` |

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

- [ ] **3.1** Add video branch to GStreamer pipeline:
  - `decodebin` auto-links to `videoconvert ! videoscale ! appsink`
  - Configure appsink for RGB format at overlay model resolution
  - Handle `new-sample` signal or pull-sample in `ProcessVideoOverlay()`

- [ ] **3.2** Implement static `IsOverlayingVideo()` and `ProcessVideoOverlay()`:
  - Same API as SDLOutput versions
  - Frame timing matched to sequence ms position

- [ ] **3.3** Update external callers:
  - `Sequence.cpp` line 739: `SDLOutput::IsOverlayingVideo()` → `GStreamerOutput::IsOverlayingVideo()`
  - `channeloutputthread.cpp` line 101: Same change

### Files Modified
| File | Change |
|------|--------|
| `src/mediaoutput/GStreamerOut.cpp` | Add video appsink branch |
| `src/mediaoutput/GStreamerOut.h` | Add static video methods |
| `src/Sequence.cpp` | Update static method calls |
| `src/channeloutput/channeloutputthread.cpp` | Update static method calls |

---

## Phase 4: HDMI/DRM Video via GStreamer

**Objective:** Replace VLC's DRM/KMS video output with GStreamer's `kmssink` for direct HDMI playback.

### Current Code
- `VLCOut.cpp` lines 224-251: DRM/KMS connector detection and VLC arg setup
- VLC3: `drm_vout` plugin. VLC4: `kms-device` + `kms-connector`
- Hardware decoding: `--avcodec-hw` toggle
- Connector status check via `/sys/class/drm/cardN-<connector>/status`

### Target Design
```cpp
// GStreamer video-to-HDMI pipeline:
// decodebin ! videoconvert ! kmssink connector-id=<id>
//
// Or with hardware decode:
// decodebin ! v4l2h264dec ! kmssink connector-id=<id>
```

### Tasks

- [ ] **4.1** Implement connector detection:
  - Reuse existing DRM connector probing logic from VLCOut.cpp
  - Map connector name (HDMI-A-1) to kmssink `connector-id` property

- [ ] **4.2** Build video-to-HDMI pipeline:
  - `decodebin ! videoconvert ! kmssink`
  - Hardware decoding via `v4l2h264dec` or `vaapidecode` (platform-specific)

- [ ] **4.3** Update factory in `mediaoutput.cpp`:
  - Video + HDMI connected → `GStreamerOutput` with kmssink (replacing VLCOutput)

- [ ] **4.4** Test with HDMI monitor:
  - Video playback to HDMI output
  - Audio synced to video via PipeWire shared clock
  - Hardware decoding if available

### Files Modified
| File | Change |
|------|--------|
| `src/mediaoutput/GStreamerOut.cpp` | Add kmssink video mode |
| `src/mediaoutput/mediaoutput.cpp` | Factory: Video+HDMI → GStreamerOutput |

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
| File | Change |
|------|--------|
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
| File |
|------|
| `src/mediaoutput/VLCOut.cpp` |
| `src/mediaoutput/VLCOut.h` |
| `src/mediaoutput/SDLOut.cpp` |
| `src/mediaoutput/SDLOut.h` |

### Files Modified
| File | Change |
|------|--------|
| `src/makefiles/fpp_so.mk` | Remove SDL/VLC/FFmpeg libs |
| `src/mediaoutput/mediaoutput.cpp` | Simplify to GStreamer-only factory |
| `src/commands/MediaCommands.cpp` | Remove HAS_VLC guards |
| `SD/FPP_Install.sh` | Update package dependencies |

---

## Dependencies

### Packages Required
```
libgstreamer1.0-dev          # Core GStreamer development
libgstreamer-plugins-base1.0-dev  # Base plugins development (appsink, etc.)
gstreamer1.0-plugins-base    # Runtime: audioconvert, audioresample, playback
gstreamer1.0-plugins-good    # Runtime: autoaudiosink, pulsesink, etc.
gstreamer1.0-plugins-bad     # Runtime: kmssink (for HDMI video)
gstreamer1.0-plugins-ugly    # Runtime: mp3 decoding (mpg123), etc.
gstreamer1.0-pipewire        # Runtime: pipewiresink/pipewiresrc elements
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
