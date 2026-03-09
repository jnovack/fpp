# PipeWire Video Routing — Design & Analysis

## Overview

Extend FPP's existing PipeWire-based audio routing (input groups / output groups)
to also carry **video signals**.  In the PipeWire graph each video stream appears
as a separate wire alongside the per-channel audio wires, giving full visibility
in tools like Helvum, qpwgraph, and `pw-dot`.

---

## Current State

| Signal | Path | PipeWire? |
|--------|------|-----------|
| Audio  | GStreamer `pipewiresink` → PipeWire combine-stream → filter-chains → ALSA sinks | **Yes** |
| Video (HDMI) | GStreamer `kmssink` — hard-wired to one DRM connector | No |
| Video (Overlay) | GStreamer `appsink` → CPU copy → PixelOverlayModel | No |

Video currently bypasses PipeWire entirely; it is baked into the GStreamer
pipeline at construction time with no runtime routing flexibility.

---

## Proposed Architecture

### Video Input Sources (input-group members)

| Source | GStreamer Element | PipeWire media.class |
|--------|------------------|---------------------|
| FPP media stream (file decode) | `pipewiresink` with `media.class=Video/Sink` | `Video/Sink` |
| Camera (Pi Camera / USB) | `libcamerasrc` or `v4l2src` → `pipewiresink` | `Video/Source` |
| Network (RTSP / NDI) | `rtspsrc` / NDI plugin → `pipewiresink` | `Video/Source` |

### Video Output Destinations (output-group members)

| Destination | Consumer | Notes |
|-------------|----------|-------|
| HDMI (DRM/KMS) | `pipewiresrc` → `videoconvert` → `kmssink` | One per HDMI port |
| Pixel Overlay (LED) | `pipewiresrc` → `videoconvert` → `appsink` | Existing overlay model |
| Network stream (RTP/NDI) | `pipewiresrc` → `rtpvrawpay` → `udpsink` | Video-over-IP |
| Virtual display (HTTP SSE) | `pipewiresrc` → `appsink` → HTTP push | Existing HTTPVirtualDisplay |

### Graph Topology

```
                        PipeWire Graph
                 ┌────────────────────────────────┐
                 │  ╔═══ Audio Wires (L,R) ═══╗   │
  fppd_stream_1 ─┤  ║ audioconvert→pipewiresink║──┤─→ fpp_group_main (combine-stream Audio/Sink)
  (GStreamer      │  ╚════════════════════════╝   │      ├→ output.main_usb → ALSA USB
   decodebin)     │                                │      └→ output.main_hdmi → ALSA HDMI
                 │  ╔═══ Video Wire (RGB) ════╗   │
                 ├─ ║ videoconvert→pipewiresink║──┤─→ fpp_video_group_main
                 │  ╚════════════════════════╝   │      ├→ output.video_hdmi1 → pipewiresrc → kmssink
                 │                                │      └→ output.video_overlay → pipewiresrc → appsink
                 │                                │
  pi_camera_1 ──┤  ╔═══ Video Wire (NV12) ═══╗  │
  (libcamerasrc   │  ║   pipewiresink          ║──┤─→ fpp_video_group_main (mixed in)
   → PipeWire)    │  ╚════════════════════════╝  │
                 └────────────────────────────────┘
```

---

## Performance Considerations

PipeWire video routing is **zero-copy** on Linux.  Video frames are passed as
DMA-BUF file descriptors — the pixel data stays in GPU memory and only the fd
reference traverses the graph.  This is the same mechanism used for Wayland
screen sharing and OBS Studio capture with negligible CPU overhead (typically
< 0.3% additional CPU compared to a direct `kmssink` path).

The routing node forwards a file descriptor pointer; it never touches the pixel
data itself.

---

## Key Challenges

| Challenge | Mitigation |
|-----------|------------|
| `combine-stream` is audio-focused | Use WirePlumber Lua linking rules for video routing instead of combine-stream |
| Video latency through PipeWire | Zero-copy dmabuf — minimal overhead vs direct kmssink |
| Pi GPU memory for multiple outputs | Pi 4/5 can drive 2 HDMI + one encode — 2–3 outputs reasonable |
| Camera packages not installed | Add `libcamera-dev`, `gstreamer1.0-libcamera` to install_pipewire.sh |
| No video EQ/delay equivalent | Delay achievable with GStreamer `queue`; EQ not applicable to video |

---

## Implementation Plan

### Phase 1 — Proof of Concept ✅

Route the existing media-file video through PipeWire instead of directly to
`kmssink`.

**Status: Complete.** GStreamerOut uses a deferred `pipewiresink` alongside
`kmssink` (via tee), WirePlumber `fpp-block-combine-fallback.lua` already
has patterns for `fppd_video_stream_*` and `fpp_video_out_*`.

**What was built:**
1. GStreamerOut.cpp — `tee` → `kmssink` (primary, direct) + deferred
   `pipewiresink` (media.class=Stream/Output/Video, node.name from setting).
2. VideoOutputManager — on-demand consumer pipelines (`pipewiresrc` → sink),
   started when producer pipewiresink attaches, stopped before producer
   pipeline teardown.
3. WirePlumber hook already handles `fpp_video_*` patterns.
4. Verified: `pw-dot` shows video wire alongside audio channels, PipeWire
   links confirmed between producer and consumer nodes.

**Key discovery:** `pipewiresrc` cannot idle without a producer — it either
blocks (autoconnect=false) or fails ("target not found"). Solution: consumers
are started on-demand when GStreamerOut's pipewiresink joins the graph and
stopped before the producer pipeline is torn down.

### Phase 2 — Video Output Groups (UI + Config) ✅

**Status: Complete.** Reworked from flat output list to group-with-members
model mirroring audio output groups.

**What was built:**
- PHP API: `GET/POST /api/pipewire/video/groups`, `POST .../groups/apply`
- Config: `pipewire-video-groups.json` (user), `pipewire-video-consumers.json` (generated)
- UI: `pipewire-video.php` — group cards with member tables (type/destination/options)
- Settings: `PipeWireVideoOutputs` modal in pipeWireVideo section
- VideoOutputManager.h/cpp — on-demand consumer pipeline lifecycle
- GStreamerOut integration — StartConsumers/StopConsumers hooks
- Build system (fpp_so.mk)

**Data model:** Each group fans out one video signal to N member outputs:
```json
{ "videoOutputGroups": [{ "id": 1, "name": "Main Video", "enabled": true,
    "members": [
      { "type": "rtp", "address": "239.0.0.1", "port": 5004 },
      { "type": "hdmi", "connector": "HDMI-A-2", "scaling": "fit" }
    ] }] }
```

**End-to-end tested:** 1 group with 3 members (RTP, Overlay, HDMI) → consumers
started on-demand. All three consumer types verified:
- **RTP**: `pipewiresrc → rtpvrawpay → udpsink` — pipeline PLAYING, PipeWire link active
- **Overlay (appsink)**: `pipewiresrc → videoconvert → videoscale → capsfilter(RGB, WxH) → appsink` —
  resolves PixelOverlayModel by name, gets dimensions, pushes RGB frames via `setData()`.
  Auto-enables disabled models, restores state on stop.
- **HDMI (kmssink)**: `pipewiresrc → videoconvert → videoscale → kmssink` — functional
  for secondary outputs; detects CRTC conflict with primary video output and skips
  gracefully when same connector is already used by GStreamerOut's main pipeline.
- Clean start/stop lifecycle: consumer nodes created on playback start, removed on stop.

**Graph visualization:** PipeWire graph page (`pipewire-graph.php`) updated to
show video nodes alongside audio: video media classes added to node whitelist,
video-specific node descriptions and enrichment properties
(`fpp.video.stream`, `fpp.video.consumer`, `fpp.video.groupId`, etc.),
video columns (col 6: Video Sources, col 7: Video Outputs), video color coding
(#0dcaf0 cyan for producers, #20b2aa lightseagreen for consumers).

### Phase 3 — Video Input Sources ✅

**Status: Complete.** Persistent video source pipelines managed by
`VideoInputManager` — sources start at fppd init and survive consumer
connect/disconnect cycles.

**What was built:**
- `VideoInputManager.h/.cpp` — C++ singleton managing GStreamer producer
  pipelines: `<source> → videoconvert → queue → pipewiresink (mode=provide)`
- `pipewiresink mode=provide` + `media.class=Video/Source` — server-side
  provider node that persists when consumers disconnect
- PHP API: `GET/POST /api/pipewire/video/input-sources`,
  `POST .../input-sources/apply`, `GET .../input-sources/v4l2-devices`
- Config: `pipewire-video-input-sources.json` (UI),
  `pipewire-video-input-sources-gen.json` (flat array for fppd)
- UI: `pipewire-video-inputs.php` — source cards with type/pattern/device/
  resolution/framerate controls
- Settings: `PipeWireVideoInputSources` in pipeWireVideo section
- `reloadVideoInputs` fppd command for live config reload
- V4L2 device enumeration endpoint

**Source types supported:**
- `videotestsrc` — GStreamer test patterns (SMPTE, snow, colors, etc.)
- `v4l2src` — USB cameras / V4L2 capture devices

**Key technical decisions:**
- `mode=provide` (enum value 2) on pipewiresink creates a server-side node
  that WirePlumber can link consumers to, unlike `Stream/Output/Video` which
  requires consumer-to-stream linking
- Requires PipeWire GStreamer plugin >= 1.7.0 (or equivalent patched build).
  Stock Debian Trixie 1.4.2 plugin crashes in `on_remove_buffer` when
  consumers disconnect from `mode=provide` producers. A guard was added in
  PipeWire 0.3.52 but regressed in 1.x. Custom-built 1.7.0 plugin resolves
  this. Backup at `libgstpipewire.so.bak-1.4.2`.
- WirePlumber `find-defined-target.lua` matches `target-object=<node.name>`
  with direction check: `Stream/Input/Video` (dir=input) targets
  `Video/Source` (dir=output)

**End-to-end tested:**
- Source starts at fppd init, appears as `Video/Source` in PipeWire graph
- Consumer connects via `target-object=fpp_video_src_1_test_pattern` → PLAYING
- Producer survives multiple consumer connect/disconnect cycles
- Reload command stops/restarts sources with updated config
- V4L2 device enumeration returns available capture devices

**Data model:**
```json
{ "videoInputSources": [{
    "id": 1, "name": "Test Pattern", "enabled": true,
    "type": "videotestsrc", "pattern": "smpte",
    "width": 320, "height": 240, "framerate": 10
}] }
```

### Phase 4 — Source Targeting ✅

**Status: Complete.** Video output groups can now target persistent video
input sources instead of only fppd media streams.

**What was built:**
- **VideoOutputManager lifecycle split**: Consumers with `sourceNode` set
  (persistent) auto-start at `Init()` and survive media stop/start.
  Consumers without `sourceNode` (on-demand) only run during media playback.
- **Consumer retry on startup**: Persistent-source consumers retry up to 5
  times with exponential backoff (1s → 2s → 4s → 8s) to handle startup
  race when the producer node hasn't registered yet.
- **Init order**: `VideoInputManager::Init()` now runs before
  `VideoOutputManager::Init()` so producer nodes exist before consumers
  attempt to connect.
- **PHP Apply pass-through**: `ApplyPipeWireVideoGroups()` maps
  `grp['videoSource']` → consumer `sourceNode` field.
- **GET API enrichment**: `GetPipeWireVideoInputSources()` computes and
  includes `pipeWireNodeName` for each source so the UI can reference them.
- **UI dropdown**: Video Output Groups page (`pipewire-video.php`) has a
  "Video Source" dropdown per group listing "Media Playback (Default)" plus
  all enabled video input sources. Selected source stored as
  `group.videoSource` (the PipeWire node name).
- **Badge indicator**: Groups targeting a persistent source show a green
  "Persistent source" badge.

**End-to-end tested:**
- Group with `videoSource=fpp_video_src_1_test_pattern` → Apply → restart →
  consumer auto-starts at Init, reaches PLAYING, PipeWire link confirmed
  (`pw-link -l` shows `fpp_video_src_1_test_pattern → consumer`)
- Group without `videoSource` → Apply → restart → consumers classified as
  on-demand (0 persistent, 3 on-demand), not started until media playback

### Building the PipeWire GStreamer Plugin from Source

The stock Debian Trixie `gstreamer1.0-pipewire` package (1.4.2) has a bug in
`gstpipewiresink.c` — the `on_remove_buffer` callback crashes when consumers
disconnect from a `pipewiresink` running in `mode=provide`.  This breaks
persistent `Video/Source` nodes (the foundation of Phase 3's input sources).

A guard for this existed in PipeWire 0.3.52 but regressed in 1.x.  Upstream
PipeWire ≥ 1.6.0 restores the fix.  Since upgrading the entire PipeWire stack
is risky on a production system, FPP ships a script that builds **only** the
GStreamer plugin module from PipeWire source and drops it in-place, leaving
the daemon, libraries, and WirePlumber at distro versions.

**Script:** `scripts/build_pipewire_gst_plugin.sh`

```bash
# Build from a known-good PipeWire tag (requires internet + root)
sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh 1.6.0

# Or from latest main branch
sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh master
```

**What it does:**
1. Installs build dependencies (`meson`, `ninja-build`, `libpipewire-0.3-dev`,
   `libgstreamer1.0-dev`, `libgstreamer-plugins-base1.0-dev`)
2. Clones PipeWire source from `gitlab.freedesktop.org`
3. Configures meson with `--auto-features=disabled -Dgstreamer=enabled` to
   only build the GStreamer plugin
4. Builds `libgstpipewire.so`
5. Backs up the stock plugin as `libgstpipewire.so.bak-<version>`
6. Installs the new plugin
7. Verifies `mode=provide` support via `gst-inspect-1.0`

**To restore the stock plugin:**
```bash
PLUGIN_DIR=/usr/lib/arm-linux-gnueabihf/gstreamer-1.0
sudo cp ${PLUGIN_DIR}/libgstpipewire.so.bak-1.4.2 ${PLUGIN_DIR}/libgstpipewire.so
```

**When is this needed?**
- Only if you use Video Input Sources (Phase 3) with `mode=provide` producers.
- If the system only uses on-demand consumers targeting fppd media streams
  (Phase 2), the stock plugin works fine.
- Future Debian releases with PipeWire ≥ 1.6.0 will not need this step.

### Phase 5 — Routing Matrix & Advanced Sources

**Status: Implemented**

Completed items:

- **Video routing matrix**: The Routing Matrix page (`pipewire-routing-matrix.php`)
  now includes a "Video Routing" section below the audio grid. Video input sources
  appear as rows, video output groups as columns. Radio buttons select which source
  feeds each group (`videoSource` field). "Media Playback" (empty source) is always
  the first row for the default fppd stream.

- **Routing presets include video**: When saving a preset, video output group
  source assignments are captured in a `videoRouting` array. Loading a preset
  restores both audio routing and video source assignments. Live-apply also
  handles video source changes (with consumer restart).

- **RTSP source type**: A new `rtspsrc` video input source type allows pulling
  RTSP network streams (e.g. IP cameras). Pipeline:
  `rtspsrc location=<uri> latency=<ms> protocols=tcp ! decodebin ! videoconvert
  ! videoscale ! caps ! queue ! pipewiresink`. Configurable URI and latency (ms)
  in the Video Input Sources UI.

- **Video routing API**: New endpoints:
  - `GET /api/pipewire/video/routing` — combined view of sources + groups + assignments
  - `POST /api/pipewire/video/routing` — save source assignments per group

Deferred to future work:

- **NDI receive**: Requires the external `gstreamer1.0-ndi` plugin which is not
  available in Debian repositories. Can be added as a source type once the plugin
  is available.
- **Compositor mixing**: GStreamer `compositor` behind a PipeWire node for
  picture-in-picture or multi-source compositing. Complex topology management
  needed.
- **Per-path controls**: Scaling, crop, position for compositor-based mixing
  (depends on compositor support).

---

## Revised Architecture: Video Output Groups

### Why Groups?

Audio output groups let you fan out one audio signal to multiple physical
sound cards with per-member volume, delay, and EQ. Video output groups
should do the same: fan out one video signal to multiple destinations with
per-member scaling.

```
                       Video Output Group "Main Video"
fppd_stream_1 ──→ fpp_video_group_main ──┬→ HDMI-A-2 (kmssink)
(pipewiresink)                            ├→ Overlay "Matrix" (appsink)
                                          └→ RTP 239.0.0.1:5004 (udpsink)
```

Without groups, each output is independent and there's no way to express
"these outputs all receive the same signal."

### Revised Config Schema

```json
{
  "videoOutputGroups": [
    {
      "id": 1,
      "name": "Main Video",
      "enabled": true,
      "members": [
        { "type": "hdmi", "connector": "HDMI-A-2", "scaling": "fit" },
        { "type": "overlay", "overlayModel": "Matrix" },
        { "type": "rtp", "address": "239.0.0.1", "port": 5004 }
      ]
    },
    {
      "id": 2,
      "name": "Network Only",
      "enabled": true,
      "members": [
        { "type": "rtp", "address": "239.0.0.2", "port": 5004 }
      ]
    }
  ]
}
```

### How It Maps to Existing Audio Pattern

| Concept | Audio | Video |
|---------|-------|-------|
| Config file | `pipewire-audio-groups.json` | `pipewire-video-groups.json` |
| Container | Group with member cards | Group with member outputs |
| Fan-out | `combine-stream` module | VideoOutputManager starts N consumers |
| Per-member | Volume, delay, EQ, channel map | Scaling, resolution |
| Routing target | `PipeWireSinkName` = group name | `PipeWireVideoSinkName` = group name |
| PipeWire node | `fpp_group_<name>` | `fpp_video_group_<name>` |

### How Routing Works With Input Sources

With Phase 3 complete, there are now two kinds of video producers:

1. **fppd media streams** (stream slots 1–5): Transient — pipewiresink
   exists only while media is playing. Uses `media.class=Stream/Output/Video`.
2. **Video input sources** (VideoInputManager): Persistent — pipewiresink
   with `mode=provide` runs from fppd init until shutdown. Uses
   `media.class=Video/Source`. Survives consumer connect/disconnect.

Consumers (VideoOutputManager) can target either:
- `consumer.sourceNode` empty → targets `m_activeProducer` (fppd stream),
  started on-demand during media playback
- `consumer.sourceNode` = `"fpp_video_src_1_test_pattern"` → targets
  persistent input source, auto-started at Init with retry logic

This means output group members can display live camera feeds, test
patterns, or media playback — all through the same consumer pipeline
infrastructure.

---

## Technical Notes

### PipeWire Video Media Classes

```
Video/Source   — produces video (camera, file decode, screen capture)
Video/Sink     — consumes video (display, encoder, recorder)
```

### GStreamer PipeWire Sink for Video

```cpp
GstElement* videoSink = gst_element_factory_make("pipewiresink", "pwvideosink");
GstStructure* vprops = gst_structure_new("props",
    "media.class", G_TYPE_STRING, "Video/Sink",
    "node.name",   G_TYPE_STRING, "fppd_video_stream_1",
    NULL);
g_object_set(videoSink, "stream-properties", vprops, NULL);
g_object_set(videoSink, "target-object", videoGroupName.c_str(), NULL);
```

### GStreamer PipeWire Source for Video Output

```cpp
GstElement* videoSrc = gst_element_factory_make("pipewiresrc", "pwvideosrc");
GstStructure* props = gst_structure_new("props",
    "media.class", G_TYPE_STRING, "Video/Source",
    "node.name",   G_TYPE_STRING, "fpp_video_out_hdmi1",
    NULL);
g_object_set(videoSrc, "stream-properties", props, NULL);
```
