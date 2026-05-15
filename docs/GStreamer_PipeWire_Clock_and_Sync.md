# GStreamer + PipeWire: Clock & Sync Architecture

## The Problem

FPP uses a GStreamer pipeline to decode media files and routes audio through
PipeWire (`pipewiresink`) while driving HDMI video via DRM/KMS (`kmssink`).
When PipeWire provides the pipeline clock (the default when `pipewiresink` is
present), two critical failures occur:

1. **Blank video on first play** — PipeWire's clock starts at time 0 and stays
   frozen until the PipeWire graph stabilizes. Every video frame has a PTS in
   the future relative to clock time 0, so `kmssink` queues frames forever
   waiting for the clock to catch up. Result: only 3 preroll frames render
   across 160 seconds of playback; `kmssink` is stuck in PAUSED.

2. **A/V desync** — Even once the PipeWire clock starts running, its rate is
   tied to the PipeWire quantum cycle (~21 ms intervals) which can drift or
   run at slightly different rates than wall-clock time. A 0.91x ratio was
   observed, meaning video ran behind audio.

## The Solution: Force GstSystemClock

The fix is a single architectural decision: **force the GStreamer pipeline to
use `GstSystemClock` (system monotonic clock) instead of the PipeWire clock**.

```cpp
// In GStreamerOut.cpp — after gst_parse_launch(), before set_state(PLAYING):
GstClock* sysClock = gst_system_clock_obtain();
gst_pipeline_use_clock(GST_PIPELINE(m_pipeline), sysClock);
gst_object_unref(sysClock);
```

### Why This Works

| Sink                     | Clock Behavior                                                                                                                                                                                                           |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **kmssink** (video)      | Paces frame rendering by comparing buffer PTS against the pipeline clock. With `GstSystemClock`, the clock is running from the start so frames render immediately and stay at the correct rate.                          |
| **pipewiresink** (audio) | With `sync=TRUE`, gates audio buffer delivery by PTS against the same `GstSystemClock`. Buffers arrive at pipewiresink at wall-clock rate. PipeWire's internal quantum scheduler handles actual hardware playout timing. |

Both sinks sync against the **same monotonic clock**, so A/V alignment is
maintained without any `ts-offset` adjustment. The sub-millisecond drift
between `GstSystemClock` and PipeWire's graph clock is negligible over typical
media durations.

### What Doesn't Work (And Why)

| Approach                               | Problem                                                                                                                               |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| PipeWire clock (default)               | Frozen at 0 on cold start — video never renders                                                                                       |
| `pipewiresink sync=FALSE`              | Audio buffers bypass PTS timing entirely — a sound effect at t=3s plays immediately at t=0                                            |
| `pipewiresink sync=TRUE` + `ts-offset` | Double-buffers latency: GStreamer adds the offset, then PipeWire's quantum scheduling adds its own ~21ms. Gets out of sync over time. |

---

## Sink Configuration Details

### Audio: pipewiresink

```cpp
g_object_set(sink, "sync", TRUE, NULL);
// No ts-offset — PipeWire filter-chain delay nodes handle
// inter-member alignment. PipeWire quantum latency (~21ms)
// ≈ DRM vsync interval (~16ms at 60Hz).
```

- **sync=TRUE** gates buffer delivery by PTS against GstSystemClock
- **No ts-offset** — previously a 456ms offset was tried to compensate for
  PipeWire buffering, but this caused double-delay since PipeWire's own
  quantum scheduling already handles playout timing
- Stream properties set `node.name` and `node.description` for PipeWire
  graph visibility

### Video: Direct kmssink (Primary HDMI)

The primary HDMI output uses a `kmssink` element directly in the GStreamer
pipeline (not routed through PipeWire):

```cpp
m_kmssink = gst_element_factory_make("kmssink", "kms");
g_object_set(m_kmssink,
    "driver-name", "vc4",
    "connector-id", m_hdmiConnectorId,
    NULL);
```

- `driver-name=vc4` — Raspberry Pi's DRM/KMS driver
- `connector-id` — resolved at runtime from sysfs (e.g., connector 43 for
  HDMI-A-2)

### Video: Consumer kmssink (PipeWire-Routed HDMI)

Additional HDMI outputs driven through PipeWire video routing use consumer
kmssink pipelines with carefully tuned properties:

```cpp
g_object_set(dkmsSink,
    "driver-name", "vc4",
    "connector-id", connectorId,
    "sync", TRUE,
    "max-lateness", (gint64)-1,
    "skip-vsync", TRUE,
    NULL);
```

| Property       | Value           | Rationale                                                                                                                                                                                                                                                                |
| -------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `sync`         | `TRUE`          | Pace rendering to PTS timestamps via the pipeline clock                                                                                                                                                                                                                  |
| `max-lateness` | `-1` (infinite) | **Never drop frames as "too late."** On cold start the clock can have a startup offset causing frames to appear late. The default max-lateness (5ms) silently drops them → blank screen. With -1, late frames render immediately; early frames still wait for their PTS. |
| `skip-vsync`   | `TRUE`          | Required for vc4 atomic modesetting — avoids double-vsync wait (kmssink waits for vsync + kernel waits for vsync = 2× frame time)                                                                                                                                        |

---

## DRM Connector Resolution

HDMI connector IDs are resolved dynamically from sysfs rather than hardcoded:

```
/sys/class/drm/card{0..7}-{connectorName}/status    → "connected" or "disconnected"
/sys/class/drm/card{0..7}-{connectorName}/connector_id → integer ID
/sys/class/drm/card{0..7}-{connectorName}/modes      → display resolutions
```

The `ResolveDrmConnector()` function:
1. Scans `/sys/class/drm/` for `cardN-<connectorName>` entries
2. Reads connection status (distinguishes "connected" from "disconnected")
3. Reads the integer connector ID for kmssink
4. Parses the first mode line for display resolution

Consumer kmssinks skip disconnected connectors — this prevents pipeline
failures when an HDMI port is configured but nothing is plugged in.

---

## Audio Startup: Flushing PipeWire Delay Buffers

PipeWire filter-chain delay nodes (used for inter-member alignment in audio
output groups) maintain ring buffers that persist between media plays. On
track start, stale audio from the previous track can leak through.

The fix: call `FlushPipeWireDelayBuffers()` early in `Start()` before
the pipeline reaches PLAYING state:

```cpp
int GStreamerOutput::Start(int msTime) {
    // Flush PipeWire filter-chain delay ring-buffers EARLY in Start().
    // This runs as a fire-and-forget thread (pw-cli calls take ~200ms+).
    // By calling it here instead of right before set_state(PLAYING),
    // the flush has the entire pipeline-build time (~50-100ms) to complete
    // before audio actually starts flowing.
    FlushPipeWireDelayBuffers();
    // ... pipeline construction follows ...
}
```

The flush uses `pw-cli` to send port-flush commands to each delay node. Since
`pw-cli` calls take ~200ms+, running it as early as possible gives it the
entire pipeline construction window to complete.

---

## Position Tracking & Diagnostics

### Position Query Priority

Stream position is queried with a preference order optimized for accuracy:

1. **Direct kmssink** (`dkms_*`) — most accurate for video, reflects actually
   rendered frames
2. **pipewiresink** (`pwsink`) — audio position, slightly ahead of actual
   playout due to PipeWire buffering
3. **Pipeline query** — fallback using `gst_element_query_position`

### 5-Second Diagnostic Logging

Every 5 seconds during playback, `Process()` logs:

```
GStreamer: wall=5.0s stream=5.0s ratio=1.00x
GStreamer: clock_time=0:00:05.002 base_time=0:00:00.001 running=0:00:05.001
GStreamer: dkms_43 rendered=300 dropped=0 state=4 pending=0
```

| Field             | Meaning                                                         |
| ----------------- | --------------------------------------------------------------- |
| `wall` / `stream` | Wall-clock elapsed vs stream position — ratio should be ~1.00x  |
| `clock_time`      | Current pipeline clock time (GstSystemClock = monotonic)        |
| `base_time`       | Pipeline base time (set when entering PLAYING)                  |
| `running`         | Clock time minus base time = effective running time             |
| `rendered`        | Frames successfully displayed by kmssink                        |
| `dropped`         | Frames dropped by kmssink (should be 0 with max-lateness=-1)    |
| `state`           | GstState: 1=NULL, 2=READY, 3=PAUSED, 4=PLAYING                  |
| `pending`         | Pending state transition (0=none, 3=PAUSED→PLAYING in progress) |

**Healthy playback indicators:**
- `ratio ≈ 1.00x` (±0.02)
- `rendered` increasing at expected FPS (e.g., 300 at 5s = 60fps)
- `dropped = 0`
- `state = 4` (PLAYING)

**Problem indicators:**
- `ratio < 0.95x` or `> 1.05x` → clock drift or buffering issue
- `rendered` stuck at 3 → PipeWire clock frozen (the original bug)
- `state = 2, pending = 3` → kmssink stuck transitioning to PAUSED
- `clock_time = 0:00:00.000` → pipeline clock not running

---

## Pipeline Topology

### Audio + Video (HDMI output with PipeWire audio routing)

```
                        GStreamer Pipeline (GstSystemClock)
┌─────────────────────────────────────────────────────────────────────┐
│                                                                     │
│  filesrc → decodebin ──┬── audioconvert → audioresample → tee ──┐  │
│                        │                                  │     │  │
│                        │              q1 → volume → pipewiresink │  │
│                        │              (sync=TRUE)                │  │
│                        │                                         │  │
│                        │              q2 → audioconvert → appsink│  │
│                        │              (audio sample tap for WLED) │  │
│                        │                                         │  │
│                        └── videoconvert → videoscale → tee ─────┐  │
│                                                           │     │  │
│                                        vtee → kmssink (HDMI)    │  │
│                                        (connector-id from sysfs)│  │
│                                                                  │  │
│                                        vtee → pipewiresink      │  │
│                                        (PipeWire video routing)  │  │
└─────────────────────────────────────────────────────────────────────┘
                              │
                     PipeWire Graph
                              │
              ┌───────────────┼──────────────────┐
              ▼               ▼                  ▼
     combine-stream    consumer kmssink    consumer appsink
     → filter-chain    (HDMI-A-2, etc.)   (PixelOverlay)
     → ALSA sink
```

### Clock Flow

```
GstSystemClock (monotonic)
        │
        ├── pipewiresink: compares buffer PTS → delivers at correct wall-clock time
        │       └── PipeWire quantum scheduler → actual HW playout
        │
        ├── kmssink (direct): compares buffer PTS → atomic modesetting at correct time
        │
        └── consumer kmssink: compares buffer PTS → render (max-lateness=-1, never drop)
```

---

## Version Requirements

All fixes are pure C++ code changes in fppd — no custom PipeWire or GStreamer
packages are required.

| Package               | Tested Version        | Notes                                              |
| --------------------- | --------------------- | -------------------------------------------------- |
| PipeWire              | 1.4.2-1+rpt3 (Trixie) | Standard Raspberry Pi Foundation package           |
| WirePlumber           | 0.5.8-2 (Trixie)      | Standard Debian package                            |
| GStreamer             | 1.26.2-* (Trixie)     | Standard Debian/RPi packages                       |
| gstreamer1.0-pipewire | 1.4.2-1+rpt3          | Stock plugin — no patches needed for audio routing |

**Note on `mode=provide`:** The stock Trixie `gstreamer1.0-pipewire` (1.4.2)
has a crash in `on_remove_buffer` when consumers disconnect from a
`pipewiresink` running in `mode=provide`. This only affects Video Input Sources
(persistent `Video/Source` nodes). The standard media playback path uses
`Stream/Output/Video` mode which is unaffected.  The script
`scripts/build_pipewire_gst_plugin.sh` can build a fixed plugin from
PipeWire >= 1.6.0 source if Video Input Sources are needed.

---

## Summary of Key Decisions

| Decision                       | Rationale                                                             |
| ------------------------------ | --------------------------------------------------------------------- |
| Force `GstSystemClock`         | PipeWire clock frozen on cold start; system monotonic always runs     |
| `pipewiresink sync=TRUE`       | Gate audio by PTS so sounds play at correct time in media             |
| No `ts-offset`                 | Avoids double-buffering latency (GStreamer offset + PipeWire quantum) |
| `kmssink max-lateness=-1`      | Never drop "late" frames — render immediately instead of blank screen |
| `kmssink skip-vsync=TRUE`      | Prevent double-vsync wait with vc4 atomic modesetting                 |
| Flush delay buffers at Start() | Clear stale audio from previous track's filter-chain ring buffers     |
| DRM connector from sysfs       | Runtime detection works across Pi models and connector configurations |
| Position from kmssink first    | Most accurate — reflects actually rendered video frames               |
