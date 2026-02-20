# FPP Input Mixing & Multi-Stream Architecture — Implementation Plan

**Created:** 2026-02-20
**Branch:** `input-mixing-phase1` (forked from `multi-input-gstreamer`)
**Status:** In Progress — Phase 1 complete, Phase 2 next

---

## Table of Contents

1. [Overview](#1-overview)
2. [Current Architecture](#2-current-architecture)
3. [Target Architecture](#3-target-architecture)
4. [Config Schema](#4-config-schema)
5. [Phase 1 — Input Group Config & PipeWire Generation](#5-phase-1--input-group-config--pipewire-generation)
6. [Phase 2 — ALSA Capture Routing](#6-phase-2--alsa-capture-routing)
7. [Phase 3 — fppd Stream Naming (Backward Compatible)](#7-phase-3--fppd-stream-naming-backward-compatible)
8. [Phase 4 — Multiple Simultaneous fppd Streams](#8-phase-4--multiple-simultaneous-fppd-streams)
9. [Phase 5 — Advanced Routing & Matrix UI](#9-phase-5--advanced-routing--matrix-ui)
10. [Visualiser Updates](#10-visualiser-updates)
11. [Risk & Rollback](#11-risk--rollback)
12. [Progress Tracking](#12-progress-tracking)

---

## 1. Overview

### Problem

FPP's PipeWire audio pipeline currently only supports a single playback stream (fppd)
feeding a single output group. There is no way to:

- Use line-in / mic capture from sound cards as audio sources
- Mix multiple input sources together before routing to output groups
- Have multiple simultaneous media streams from fppd
- Route different input sources to different output groups independently

### Solution

Introduce **Input Groups** (mix buses) that sit between input sources and output groups,
creating a full mixing-desk architecture:

```
Input Sources  →  Input Groups  →  Output Groups  →  Effects  →  HW Sinks
              (mix buses)      (combine-streams)  (delay/EQ)  (ALSA/AES67)
```

### Design Principles

- **Backward compatible** — existing output group configs work unchanged
- **fppd defaults to `fppd_stream_1`** — all existing media/playlist functionality
  continues to work identically, just with a named stream node
- **Incremental** — each phase is independently deployable and testable
- **PipeWire-native** — use combine-stream and loopback modules, no custom DSP code
- **Config-driven** — PHP generates PipeWire `.conf` files from JSON config, same
  pattern as existing output groups

---

## 2. Current Architecture

### Signal Flow

```
fppd (GStreamer)
  └─ pipewiresink (target-object=fpp_group_*)
       └─ Output Group (combine-stream sink)
            ├─ filter-chain (delay+EQ) → ALSA sink (Sound Blaster)
            ├─ filter-chain (delay)     → ALSA sink (ICUSBAUDIO7D)
            └─ filter-chain (delay)     → AES67 send (GStreamer pipewiresrc)
```

### Key Files

| File                                                     | Purpose                                                 |
| -------------------------------------------------------- | ------------------------------------------------------- |
| `src/mediaoutput/GStreamerOut.cpp`                       | fppd GStreamer playback pipeline, single `pipewiresink` |
| `src/mediaoutput/AES67Manager.cpp`                       | AES67 send/receive GStreamer pipelines                  |
| `www/api/controllers/pipewire.php`                       | Audio group CRUD, PipeWire config generation, graph API |
| `www/pipewire-audio.php`                                 | Output group configuration UI                           |
| `www/pipewire-graph.php`                                 | D3.js pipeline visualiser (4-column layout)             |
| `www/aes67-config.php`                                   | AES67 instance configuration UI                         |
| `<media>/config/pipewire-audio-groups.json`              | Output group definitions                                |
| `<media>/config/pipewire-aes67-instances.json`           | AES67 instance definitions                              |
| `/etc/pipewire/pipewire.conf.d/97-fpp-audio-groups.conf` | Generated PipeWire config                               |

### PipeWire Modules Used

- `libpipewire-module-combine-stream` — merges multiple sinks into one (output groups)
- `libpipewire-module-filter-chain` — per-member delay + EQ processing
- WirePlumber linking hook — prevents rogue default-target fallback

### Unused Capture Devices

ALSA capture devices (`Audio/Source`) are enumerated by WirePlumber and visible in
PipeWire but are not consumed by any FPP feature. They appear in the graph visualiser's
"Inputs" column but have no outgoing links.

---

## 3. Target Architecture

### Signal Flow (Full Implementation)

```
┌──────────────────┐    ┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐    ┌──────────────┐
│  Input Sources    │    │  Input Groups    │    │  Output Groups   │    │  Effect Chains   │    │   HW Sinks   │
│                   │    │  (Mix Buses)     │    │  (Combine)       │    │  (Delay/EQ)      │    │              │
│ fppd_stream_1  ───┼───►│                 ├───►│                  ├───►│                  ├───►│ ALSA sink    │
│ fppd_stream_2  ───┼───►│  "Main Mix"     │    │  "Speakers"      │    │  delay+EQ: SB    │    │ AES67 send   │
│ ALSA line-in   ───┼───►│                 │    │                  │    │  delay: AES67    │    │              │
│ AES67 receive  ───┼───►│                 │    │                  │    │                  │    │              │
│                   │    │  "Announcements"─┼───►│  "PA System"     │    │  delay: PA amp   │    │              │
└──────────────────┘    └─────────────────┘    └──────────────────┘    └─────────────────┘    └──────────────┘
```

### Visualiser Columns (expanded from 4 → 6)

| Col | Label         | Node Types                                    |
| --- | ------------- | --------------------------------------------- |
| 0   | Input Sources | ALSA capture, AES67 recv, fppd streams        |
| 1   | Input Groups  | `fpp_input_*` combine-stream mix buses        |
| 2   | Output Groups | `fpp_group_*` combine-stream sinks (existing) |
| 3   | Effects       | `fpp_fx_*` filter-chains (existing)           |
| 4   | HW Outputs    | ALSA sinks, AES67 send pipewiresrc capture    |

### PipeWire Modules (new)

- `libpipewire-module-combine-stream` (sink mode) — for input group mixing
- `libpipewire-module-loopback` — bridges `Audio/Source` → input group sink

### New Config File

`<media>/config/pipewire-input-groups.json` — input group definitions

### New Generated Config

`/etc/pipewire/pipewire.conf.d/96-fpp-input-groups.conf` — generated before
the existing `97-fpp-audio-groups.conf` so input group nodes exist when output
groups are created.

---

## 4. Config Schema

### Input Groups — `pipewire-input-groups.json`

```json
{
  "inputGroups": [
    {
      "id": 1,
      "name": "Main Mix",
      "enabled": true,
      "channels": 2,
      "volume": 100,
      "members": [
        {
          "type": "fppd_stream",
          "sourceId": "fppd_stream_1",
          "name": "Media Playback",
          "volume": 100,
          "mute": false
        },
        {
          "type": "capture",
          "cardId": "ICUSBAUDIO7D",
          "name": "USB Line In",
          "volume": 80,
          "mute": false,
          "channelMapping": {
            "sourceChannels": ["FL"],
            "groupChannels": ["FL", "FR"]
          }
        },
        {
          "type": "aes67_receive",
          "instanceId": "aes67_rx_1",
          "name": "AES67 Feed",
          "volume": 100,
          "mute": false
        }
      ],
      "outputs": [1]
    }
  ]
}
```

### Member Types

| `type`          | `sourceId` / `cardId` | PipeWire Source Node                           |
| --------------- | --------------------- | ---------------------------------------------- |
| `fppd_stream`   | `fppd_stream_1`       | `Stream/Output/Audio` from fppd GStreamer      |
| `capture`       | ALSA card ID          | `Audio/Source` node managed by WirePlumber     |
| `aes67_receive` | AES67 instance ID     | `Audio/Source` from AES67Manager recv pipeline |

### Routing — `outputs` Field

The `outputs` array contains output group IDs. An input group's mixed output is
routed to each listed output group via PipeWire loopback modules:

```
fpp_input_main_mix (mix bus output) → fpp_group_speakers (combine-stream input)
                                    → fpp_group_pa_system (combine-stream input)
```

### Backward Compatibility — No Input Groups Configured

When `pipewire-input-groups.json` does not exist or contains no enabled groups,
fppd routes directly to the output group via `PipeWireSinkName` as it does today.
This maintains 100% backward compatibility.

---

## 5. Phase 1 — Input Group Config & PipeWire Generation

**Goal:** PHP-only implementation of input group config, PipeWire config generation,
and basic UI. No fppd C++ changes. fppd still creates a single stream but it can be
added as an input group member.

### Tasks

- [x] **1.1** Create API endpoints for input group CRUD ✅
  - `GET /api/pipewire/audio/input-groups` — list all input groups
  - `POST /api/pipewire/audio/input-groups` — save input groups config
  - `POST /api/pipewire/audio/input-groups/apply` — generate config & restart PipeWire
  - `GET /api/pipewire/audio/sources` — enumerate ALSA capture devices (pw-dump)
  - File: `www/api/controllers/pipewire.php`
  - File: `www/api/index.php` (4 new routes registered)

- [x] **1.2** Create `GeneratePipeWireInputGroupsConfig()` function ✅
  - Generates `libpipewire-module-combine-stream` per input group (sink mode)
  - Generates `libpipewire-module-loopback` per capture/AES67 member (source → group)
  - Generates `libpipewire-module-loopback` per output routing (group → output group)
  - fppd_stream members handled via combine-stream `stream.rules` match (no loopback)
  - Per-member volume via loopback props (0-100 → 0.0-1.0)
  - Muted members skip loopback creation entirely
  - Written to `/etc/pipewire/pipewire.conf.d/96-fpp-input-groups.conf`
  - Cached in `<media>/config/pipewire-input-groups.conf`

- [x] **1.3** Update `ApplyPipeWireAudioGroups()` to also regenerate input group config ✅
  - Input group config regenerated in sync when output groups are applied
  - 96 < 97 ordering maintained
  - `ApplyPipeWireInputGroups()` also updates fppd routing target when
    fppd_stream_1 is a member of an input group

- [x] **1.4** Create input mixing UI page: `www/pipewire-input-mixing.php` ✅
  - Mixing desk layout with member rows per input group
  - Per-member: type selector (fppd_stream/capture/aes67_receive),
    source picker, name label, volume slider, mute toggle, remove button
  - Output group routing: checkbox per enabled output group
  - Loads data in parallel: input groups, capture sources, output groups
  - Save & Apply buttons with jGrowl feedback
  - Modal mode support (`?modal=1`)

- [x] **1.5** Add menu entry for input mixing page ✅
  - Added `PipeWireInputMixing` to `settings.json` as modal type
  - Appears in PipeWire Audio section alongside Audio Groups and AES67
  - Listed under `AudioBackend` → `pipewire` children

- [x] **1.6** Update graph API to handle input group nodes ✅
  - `GetPipeWireGraph()` enriches `fpp_input_*` nodes with:
    `fpp.inputGroup`, `fpp.inputGroup.members`, `fpp.inputGroup.outputs`
  - Enriches `fpp_loopback_ig*` with `fpp.inputGroup.loopback`, `.id`
  - Enriches `fpp_route_ig*_to_og*` with route metadata

- [x] **1.7** Update WirePlumber hook to cover input group nodes ✅
  - Blocks default-target fallback for `output.fpp_input_*` (combine outputs)
  - Blocks fallback for `fpp_loopback_ig*` (source routing loopbacks)
  - Blocks fallback for `fpp_route_ig*_to_og*` (group-to-group routes)

### Implementation Notes (Phase 1)

- **Commit:** `2c3ba3c4` on branch `input-mixing-phase1`
- **Visualiser expanded to 5 columns:** Input Sources → Input Groups → Output Groups → Effects → HW Outputs
- **Node merging** added for `output.fpp_input_*` (same pattern as `output.fpp_group_*`)
- **Legend** updated with Input Group entry (#e35d6a)
- **Barycenter layout** sweeps updated from 4→5 columns
- **Files changed:** pipewire.php (+549), index.php (+4), pipewire-graph.php (+60/-20),
  pipewire-input-mixing.php (+691 new), settings.json (+15)

### PipeWire Config Generation Detail

For an input group "Main Mix" with an ALSA capture member:

```
# Input Group: Main Mix
context.modules = [
  {
    name = libpipewire-module-combine-stream
    args = {
      combine.mode = sink
      node.name = fpp_input_main_mix
      node.description = "Main Mix"
      audio.position = [ FL FR ]
      stream.rules = [
        {
          matches = [ { node.name = "fpp_loopback_ig1_*" } ]
          actions = { create-stream = { } }
        }
      ]
    }
  }
  # Loopback: route ALSA capture → input group
  {
    name = libpipewire-module-loopback
    args = {
      node.name = fpp_loopback_ig1_icusbaudio7d
      node.description = "USB Line In → Main Mix"
      capture.props = {
        node.target = alsa_input.usb-0d8c_USB_Sound_Device-00.analog-stereo
        media.class = Stream/Input/Audio
        stream.dont-remix = true
      }
      playback.props = {
        node.target = fpp_input_main_mix
        media.class = Stream/Output/Audio
      }
    }
  }
]
```

### Testing Criteria

- [ ] Input group appears as a node in the PipeWire graph
- [ ] ALSA capture source audio routes through input group to output group and out speakers
- [ ] Volume slider adjusts input member level in real-time
- [ ] Mute toggle silences individual input
- [ ] Graph visualiser shows correct routing with 5 columns
- [ ] Removing all input groups reverts to direct fppd → output group routing
- [ ] Save & Apply with active playback stops/resumes correctly

---

## 6. Phase 2 — ALSA Capture Routing

**Goal:** Full support for ALSA line-in/mic capture devices as input group members
with per-source controls.

### Tasks

- [ ] **2.1** Add API endpoint to enumerate available capture devices
  - `GET /api/pipewire/audio/sources` — returns ALSA `Audio/Source` nodes from pw-dump
  - Include card ID, description, available channels, sample rate
  - Filter out virtual/internal sources (PipeWire monitors etc.)

- [ ] **2.2** UI: source device picker in input group member config
  - Dropdown populated from `/api/pipewire/audio/sources`
  - Show card name, channel count, current state
  - Channel mapping selector (mono source → stereo group, etc.)

- [ ] **2.3** Per-member gain control via filter-chain
  - Inline biquad gain node in the loopback path for fine volume control
  - Alternative: use PipeWire stream volume on the loopback node (simpler)
  - Decision: start with stream volume (pactl), add filter-chain gain if needed

- [ ] **2.4** Source monitoring / metering
  - Optionally show input level meter (peak/RMS) per source
  - Use PipeWire peak detection or poll `pw-top` data
  - Nice-to-have, not blocking

- [ ] **2.5** Handle hot-plug / device removal
  - If an ALSA capture device is unplugged, the loopback should not crash PipeWire
  - WirePlumber will handle graceful disconnect; UI should show "disconnected" state
  - On re-plug, WirePlumber rescans and loopback reconnects automatically

### Testing Criteria

- [ ] USB sound card line-in audio appears in output group mix
- [ ] Unplugging/replugging capture device recovers automatically
- [ ] Channel mapping (mono → stereo) works correctly
- [ ] Multiple capture sources can be mixed simultaneously
- [ ] No audio glitches or xruns during normal operation

---

## 7. Phase 3 — fppd Stream Naming (Backward Compatible)

**Goal:** Name fppd's GStreamer audio output `fppd_stream_1` so it appears as a
distinct, identifiable node in the PipeWire graph and can be referenced as an
input group member. **All existing behaviour is preserved.**

### Design

- fppd already creates a `pipewiresink` with `target-object=<group_sink>`
- Add `node.name=fppd_stream_1` and `node.description=FPP Media Stream 1`
  to the `stream-properties` on the `pipewiresink` element
- When no input groups are configured, `target-object` still points directly
  at the output group (backward compatible)
- When input groups are configured, `target-object` points at the input group
  instead (PHP sets `PipeWireSinkName` to the input group)

### Tasks

- [ ] **3.1** Add `node.name` to GStreamer pipewiresink stream-properties
  - File: `src/mediaoutput/GStreamerOut.cpp` (~line 243)
  - Add: `node.name=fppd_stream_1,node.description=FPP Media Stream 1`
  - Default stream ID: `fppd_stream_1` (hardcoded for now)
  - Rebuild fppd

- [ ] **3.2** Update `ApplyPipeWireAudioGroups()` routing logic
  - If input groups exist and fppd_stream_1 is a member of an input group:
    set `PipeWireSinkName` to the input group's node name
  - If no input groups: set `PipeWireSinkName` to the output group (unchanged)
  - This determines what fppd's `pipewiresink target-object` connects to

- [ ] **3.3** Update AES67Manager recv pipeline naming
  - Add explicit `node.name=aes67_<instance>_recv` (already done, verify)
  - Ensure the name matches what input group config references

- [ ] **3.4** Update graph visualiser classification
  - `classifyColumn()`: `fppd_stream_*` → column 0 (Input Sources)
  - Currently shows as `fppd` — will now show as `fppd_stream_1`
  - Node description: "FPP Media Stream 1"

- [ ] **3.5** Update WirePlumber hook
  - Add `fppd_stream_*` pattern to the linking hook if needed
  - Ensure fppd streams don't get rogue default-target links when input groups are active

### Testing Criteria

- [ ] fppd audio output appears as "FPP Media Stream 1" (`fppd_stream_1`) in graph
- [ ] Audio plays correctly through output group (no regression)
- [ ] All existing playlist functionality works identically
- [ ] Volume control via `@DEFAULT_AUDIO_SINK@` still works
- [ ] Graph visualiser correctly classifies the named stream

---

## 8. Phase 4 — Multiple Simultaneous fppd Streams

**Goal:** Allow fppd to run multiple GStreamer playback pipelines simultaneously,
each appearing as a separate named stream in PipeWire.

### Design

```
fppd
  ├─ GStreamerOutput slot 1 → pipewiresink (fppd_stream_1) → "Main Mix"
  ├─ GStreamerOutput slot 2 → pipewiresink (fppd_stream_2) → "Announcements"
  └─ GStreamerOutput slot 3 → pipewiresink (fppd_stream_3) → "Background"
```

### Tasks

- [ ] **4.1** Refactor `GStreamerOutput` to support multiple instances
  - Currently a singleton — refactor to allow N instances with unique IDs
  - Each instance gets: `fppd_stream_<N>`, its own GStreamer pipeline, its own volume
  - Pool size configurable (default: 4 slots)
  - File: `src/mediaoutput/GStreamerOut.h`, `src/mediaoutput/GStreamerOut.cpp`

- [ ] **4.2** Add stream slot parameter to playlist media entries
  - `PlaylistEntryMedia` gets an optional `slot` field (default: 1)
  - File: `src/playlist/PlaylistEntryMedia.h`, `src/playlist/PlaylistEntryMedia.cpp`
  - Playlist JSON: `{ "type": "media", "mediaName": "song.mp3", "slot": 1 }`

- [ ] **4.3** Per-stream volume control
  - Each `pipewiresink` has its own volume element in the GStreamer pipeline
  - Per-stream volume API: `POST /api/pipewire/audio/stream/<N>/volume`
  - Or use PipeWire stream volume via `pactl set-sink-input-volume`

- [ ] **4.4** Multi-stream playlist scheduling
  - Allow playlist entries to specify which stream slot to use
  - Entries on different slots can overlap (crossfade, background music + announcements)
  - Scheduler changes in `src/playlist/Playlist.cpp`

- [ ] **4.5** Update playlist editor UI
  - Stream slot selector per playlist entry
  - Visual indication of which slot each entry uses
  - File: `www/playlist.php`, `www/js/fpp.js`

- [ ] **4.6** WLED audio-reactive tap
  - Currently tapped via `appsink` on the single pipeline
  - With multiple streams, decide: tap stream 1 only? Mix? Configurable?
  - Simplest: always tap stream 1 (default, backward compatible)

- [ ] **4.7** Status API updates
  - `GET /api/fppd/status` should report per-stream status
  - Current song, elapsed time, state per stream slot
  - Backward compatible: `current_song` field remains, reports stream 1

### Testing Criteria

- [ ] Two songs can play simultaneously on different stream slots
- [ ] Each stream appears as separate node in PipeWire graph
- [ ] Per-stream volume works independently
- [ ] Stopping one stream doesn't affect others
- [ ] Single-stream playlists work identically (slot defaults to 1)
- [ ] WLED audio-reactive still works (taps stream 1)
- [ ] Status API reports all active streams

---

## 9. Phase 5 — Advanced Routing & Matrix UI

**Goal:** Full routing matrix, multiple output groups, input group effects.

### Tasks

- [ ] **5.1** Routing matrix UI
  - Grid view: input groups (rows) × output groups (columns)
  - Checkbox + volume per routing path
  - Visual representation of the full signal flow

- [ ] **5.2** Per-routing-path volume
  - Individual volume control on each input→output route
  - Implemented via loopback volume or filter-chain gain

- [ ] **5.3** Input group effects
  - Optional EQ/compression on the input group's mixed output
  - Before routing to output groups
  - Reuse filter-chain generation from output groups

- [ ] **5.4** Group templates / presets
  - Save/load routing configurations
  - "Christmas Show", "Background Music", "PA Announcement" presets

- [ ] **5.5** Live mixing controls
  - Real-time fader adjustments without Save & Apply
  - WebSocket or polling-based UI updates
  - PipeWire param changes via `pw-cli set-param`

---

## 10. Visualiser Updates

### Per-Phase Changes

| Phase | Visualiser Change                                             |
| ----- | ------------------------------------------------------------- |
| 1     | Add columns 1 (Input Groups) to layout, expand to 5-6 columns |
| 2     | Show ALSA capture sources linked to input groups via loopback |
| 3     | Rename `fppd` node to `fppd_stream_1`, classify to column 0   |
| 4     | Multiple `fppd_stream_N` nodes in column 0                    |
| 5     | Show routing paths with per-path volume/mute indicators       |

### `classifyColumn()` Changes (Phase 1)

```javascript
function classifyColumn(n) {
    const nm = n.name || '';
    const mc = n.mediaClass || '';

    // Input groups (mix buses)
    if (nm.startsWith('fpp_input_')) return 1;
    if (nm.startsWith('fpp_loopback_')) return 1;

    // Output groups (existing)
    if (nm.startsWith('fpp_group_')) return 2;

    // Effects (existing)
    if (nm.startsWith('fpp_fx_') || nm.startsWith('fpp_eq_')) return 3;

    // HW outputs
    if (mc === 'Audio/Sink' && nm.startsWith('alsa_')) return 4;
    if (mc === 'Stream/Input/Audio') return 4;

    // Input sources
    if (mc === 'Audio/Source') return 0;
    if (mc === 'Stream/Output/Audio') return 0;
    if (mc === 'Audio/Sink') return 4;

    return 0;
}

const COL_LABELS = ['Input Sources', 'Input Groups', 'Output Groups', 'Effects', 'HW Outputs'];
```

### Node Merging Updates

- Merge `fpp_loopback_*` pairs (capture + playback) into single nodes
  (same pattern as `fpp_fx_*` / `fpp_fx_*_out` merging)
- Merge `fpp_input_*` combine-stream output nodes into parent

---

## 11. Risk & Rollback

### Mitigation Strategy

- **Fork before implementation** — create new branch from current working state
- **Phase gates** — each phase is independently testable and deployable
- **Config backward compatibility** — missing config files = existing behaviour
- **Feature flag** — input groups only activate when config exists

### Known Risks

| Risk                                            | Mitigation                                                      |
| ----------------------------------------------- | --------------------------------------------------------------- |
| PipeWire restart race conditions                | Already mitigated: stop fppd before restart (implemented)       |
| WirePlumber rogue linking with more nodes       | Extend existing Lua hook for new node name patterns             |
| Loopback latency adds to pipeline latency       | Measure; loopback adds ~1 buffer period (~5ms at 48kHz)         |
| Hot-plug of capture devices during playback     | WirePlumber handles gracefully; test thoroughly                 |
| Multiple GStreamer pipelines increase CPU       | Profile on RPi 5; GStreamer is efficient, expect <5% per stream |
| Playlist scheduler complexity with multi-stream | Phase 4 is optional; single-stream works without it             |

### Rollback Plan

1. Delete `/etc/pipewire/pipewire.conf.d/96-fpp-input-groups.conf`
2. Remove `<media>/config/pipewire-input-groups.json`
3. Restart PipeWire services
4. System reverts to direct fppd → output group routing

---

## 12. Progress Tracking

### Phase 1 — Input Group Config & PipeWire Generation ✅ IMPLEMENTED
- [x] 1.1 API endpoints for input group CRUD
- [x] 1.2 PipeWire config generation (combine-stream + loopback)
- [x] 1.3 Integrate with apply/restart flow
- [x] 1.4 Input mixing UI page
- [x] 1.5 Menu entry (settings.json modal)
- [x] 1.6 Graph API enrichment
- [x] 1.7 WirePlumber hook update
- [ ] Phase 1 testing complete

### Phase 2 — ALSA Capture Routing
- [ ] 2.1 Capture device enumeration API
- [ ] 2.2 Source device picker UI
- [ ] 2.3 Per-member gain control
- [ ] 2.4 Source metering (nice-to-have)
- [ ] 2.5 Hot-plug handling
- [ ] Phase 2 testing complete

### Phase 3 — fppd Stream Naming
- [ ] 3.1 Add node.name to GStreamer pipewiresink
- [ ] 3.2 Update routing logic for input groups
- [ ] 3.3 AES67 recv naming verification
- [ ] 3.4 Visualiser classification update
- [ ] 3.5 WirePlumber hook update
- [ ] Phase 3 testing complete

### Phase 4 — Multiple fppd Streams
- [ ] 4.1 GStreamerOutput multi-instance refactor
- [ ] 4.2 Playlist slot parameter
- [ ] 4.3 Per-stream volume control
- [ ] 4.4 Multi-stream scheduling
- [ ] 4.5 Playlist editor UI
- [ ] 4.6 WLED audio-reactive tap
- [ ] 4.7 Status API updates
- [ ] Phase 4 testing complete

### Phase 5 — Advanced Routing
- [ ] 5.1 Routing matrix UI
- [ ] 5.2 Per-routing-path volume
- [ ] 5.3 Input group effects
- [ ] 5.4 Group templates / presets
- [ ] 5.5 Live mixing controls
- [ ] Phase 5 testing complete
