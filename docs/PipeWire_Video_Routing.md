# PipeWire Video Routing

## Overview

FPP routes video signals through PipeWire alongside audio, giving full graph
visibility in tools like Helvum, qpwgraph, and `pw-dot`. Video output groups
fan one video signal out to multiple destinations; video input sources provide
persistent producer nodes that survive consumer connect/disconnect cycles.

---

## Signal Flow

| Signal          | Path                                                                              | PipeWire? |
| --------------- | --------------------------------------------------------------------------------- | --------- |
| Audio           | GStreamer `pipewiresink` → PipeWire combine-stream → filter-chains → ALSA sinks   | Yes       |
| Video (media)   | GStreamer `pipewiresink` (Stream/Output/Video) → VideoOutputManager consumers     | Yes       |
| Video (source)  | VideoInputManager `pipewiresink mode=provide` (Video/Source) → consumers          | Yes       |

---

## Architecture

### Graph Topology

```
                        PipeWire Graph
                 ┌────────────────────────────────┐
                 │  ╔═══ Audio Wires (L,R) ═══╗   │
  fppd_stream_1 ─┤  ║ audioconvert→pipewiresink║──┤─→ fpp_group_main (combine-stream Audio/Sink)
  (GStreamer      │  ╚════════════════════════╝   │      ├→ output.main_usb → ALSA USB
   decodebin)     │                                │      └→ output.main_hdmi → ALSA HDMI
                 │  ╔═══ Video Wire (RGB) ════╗   │
                 ├─ ║ videoconvert→pipewiresink║──┤─→ fpp_video_group_main (VideoOutputManager)
                 │  ╚════════════════════════╝   │      ├→ output.video_hdmi1 → pipewiresrc → kmssink
                 │                                │      └→ output.video_overlay → pipewiresrc → appsink
                 │                                │
  VideoInput ───┤  ╔═══ Video Wire (NV12) ═══╗  │
  (mode=provide  │  ║   pipewiresink          ║──┤─→ fpp_video_group_main (persistent targeting)
   Video/Source) │  ╚════════════════════════╝  │
                 └────────────────────────────────┘
```

### Video Producers

There are two kinds of video producers:

| Producer | Class | Lifetime | Source |
| -------- | ----- | -------- | ------ |
| fppd media stream (slots 1–5) | `Stream/Output/Video` | Transient — exists only during media playback | GStreamerOut |
| Video input source | `Video/Source` (`mode=provide`) | Persistent — from fppd init until shutdown | VideoInputManager |

`mode=provide` creates a server-side PipeWire node that WirePlumber can link
consumers to; it survives consumer connect/disconnect cycles. Consumers target
it via `target-object=<node.name>`.

### Video Consumers (VideoOutputManager)

Consumer pipelines are created per output group member:

| Type | Pipeline | Notes |
| ---- | -------- | ----- |
| `hdmi` | `pipewiresrc → videoconvert → videoscale → kmssink` | Skips gracefully if connector already used by primary pipeline |
| `overlay` | `pipewiresrc → videoconvert → videoscale → capsfilter(RGB) → appsink` | Resolves PixelOverlayModel by name; auto-enables disabled models |
| `rtp` | `pipewiresrc → rtpvrawpay → udpsink` | Video-over-IP multicast/unicast |

**Lifecycle:**
- Consumers with `sourceNode` set (persistent source) auto-start at `Init()`
  with up to 5 retries (exponential backoff: 1 s → 2 s → 4 s → 8 s) to handle
  startup races.
- Consumers without `sourceNode` (media stream) start on playback start and
  stop before the producer pipeline tears down.

---

## Configuration

### Video Output Groups

Config file: `pipewire-video-groups.json`

```json
{ "videoOutputGroups": [
    { "id": 1, "name": "Main Video", "enabled": true,
      "videoSource": "fpp_video_src_1_test_pattern",
      "members": [
        { "type": "hdmi", "connector": "HDMI-A-2", "scaling": "fit" },
        { "type": "overlay", "overlayModel": "Matrix" },
        { "type": "rtp", "address": "239.0.0.1", "port": 5004 }
      ]
    }
  ]
}
```

`videoSource` is the PipeWire node name of a persistent input source.
Omit it (or set to empty) to target the fppd media stream instead.

API: `GET/POST /api/pipewire/video/groups`, `POST .../groups/apply`

### Video Input Sources

Config file: `pipewire-video-input-sources.json`

```json
{ "videoInputSources": [
    { "id": 1, "name": "Test Pattern", "enabled": true,
      "type": "videotestsrc", "pattern": "smpte",
      "width": 320, "height": 240, "framerate": 10 },
    { "id": 2, "name": "USB Camera", "enabled": true,
      "type": "v4l2src", "device": "/dev/video0",
      "width": 640, "height": 480, "framerate": 30 },
    { "id": 3, "name": "IP Camera", "enabled": true,
      "type": "rtspsrc", "uri": "rtsp://192.168.1.10/stream",
      "latency": 200 }
  ]
}
```

| Type | Pipeline |
| ---- | -------- |
| `videotestsrc` | `videotestsrc pattern=<p> ! videoconvert ! queue ! pipewiresink` |
| `v4l2src` | `v4l2src device=<d> ! videoconvert ! videoscale ! caps ! queue ! pipewiresink` |
| `rtspsrc` | `rtspsrc location=<uri> latency=<ms> protocols=tcp ! decodebin ! videoconvert ! videoscale ! caps ! queue ! pipewiresink` |

API: `GET/POST /api/pipewire/video/input-sources`, `POST .../input-sources/apply`,
`GET .../input-sources/v4l2-devices`, fppd command `reloadVideoInputs`

### Routing

The Routing Matrix page (`pipewire-routing-matrix.php`) includes a Video
Routing section. Source assignments per group are saved in a `videoRouting`
array and included in routing presets (save/load restores both audio and video
assignments).

API: `GET /api/pipewire/video/routing`, `POST /api/pipewire/video/routing`

---

## Audio / Video Mapping

| Concept        | Audio                            | Video                                 |
| -------------- | -------------------------------- | ------------------------------------- |
| Config file    | `pipewire-audio-groups.json`     | `pipewire-video-groups.json`          |
| Fan-out        | `combine-stream` PipeWire module | VideoOutputManager starts N consumers |
| Per-member     | Volume, delay, EQ, channel map   | Scaling, resolution                   |
| PipeWire node  | `fpp_group_<name>`               | `fpp_video_group_<name>`              |

---

## PipeWire Graph Visualization

`pipewire-graph.php` shows video nodes alongside audio:

- Video media classes included in node whitelist
- Column 6: Video Sources, Column 7: Video Outputs
- Color coding: `#0dcaf0` cyan for producers, `#20b2aa` lightseagreen for consumers
- Node properties: `fpp.video.stream`, `fpp.video.consumer`, `fpp.video.groupId`

---

## GStreamer PipeWire Plugin Compatibility

The stock Debian Trixie `gstreamer1.0-pipewire` package (1.4.2) crashes in
`on_remove_buffer` when consumers disconnect from a `pipewiresink` running in
`mode=provide`. This affects persistent `Video/Source` nodes (VideoInputManager).
A fix exists in PipeWire ≥ 1.6.0.

FPP ships a script that builds only the GStreamer plugin module from PipeWire
source, leaving the daemon, libraries, and WirePlumber at distro versions:

```bash
# Build from a known-good tag
sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh 1.6.0

# Or from latest main
sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh master
```

The script: installs build deps, clones PipeWire source, configures meson
with only `gstreamer=enabled`, builds `libgstpipewire.so`, backs up the stock
plugin as `libgstpipewire.so.bak-<version>`, installs the new plugin.

To restore the stock plugin:
```bash
PLUGIN_DIR=/usr/lib/arm-linux-gnueabihf/gstreamer-1.0
sudo cp ${PLUGIN_DIR}/libgstpipewire.so.bak-1.4.2 ${PLUGIN_DIR}/libgstpipewire.so
```

This is only required when using Video Input Sources (`mode=provide` producers).
On-demand consumers targeting fppd media streams work with the stock plugin.
Future Debian releases with PipeWire ≥ 1.6.0 will not need this step.

---

## Performance

PipeWire video routing is zero-copy on Linux. Video frames are passed as
DMA-BUF file descriptors — pixel data stays in GPU memory, only the fd
reference traverses the graph. Additional CPU overhead vs a direct `kmssink`
path is typically < 0.3%.

---

## Known Limitations / Deferred Work

- **NDI receive**: Requires `gstreamer1.0-ndi` plugin, not in Debian repos.
- **Compositor mixing**: GStreamer `compositor` for picture-in-picture or
  multi-source compositing — complex topology management needed.
- **Per-path controls**: Scaling, crop, position for compositor-based mixing
  (depends on compositor support).
