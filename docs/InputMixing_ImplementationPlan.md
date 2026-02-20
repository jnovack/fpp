# FPP Input Mixing & Multi-Stream Architecture — Implementation Plan

**Created:** 2026-02-20
**Branch:** `input-mixing-phase1` (forked from `multi-input-gstreamer`)
**Status:** Complete — Phases 1–5 implemented

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

- [x] Input group appears as a node in the PipeWire graph
- [x] ALSA capture source audio routes through input group to output group and out speakers
- [x] Volume slider adjusts input member level in real-time
- [x] Mute toggle silences individual input
- [x] Graph visualiser shows correct routing with 5 columns
- [ ] Removing all input groups reverts to direct fppd → output group routing
- [ ] Save & Apply with active playback stops/resumes correctly

---

## 6. Phase 2 — ALSA Capture Routing

**Goal:** Full support for ALSA line-in/mic capture devices as input group members
with per-source controls.

### Tasks

- [x] **2.1** Add API endpoint to enumerate available capture devices ✅ (implemented in Phase 1)
  - `GET /api/pipewire/audio/sources` — returns ALSA `Audio/Source` nodes from pw-dump
  - Include card ID, description, available channels, sample rate, state
  - Filters out `.monitor` virtual sources
  - File: `www/api/controllers/pipewire.php` (`GetPipeWireAudioSources()`)

- [x] **2.2** UI: source device picker in input group member config ✅
  - ALSA capture: dropdown populated from `/api/pipewire/audio/sources`
  - Shows card name, channel count, device state badge (running/idle/disconnected)
  - Channel mapping selector appears when source channels ≠ group channels
  - Presets: Mono→Center, Mono→L+R, Mono→L only, Stereo→Front, Stereo→Rear, etc.
  - AES67 receive: dropdown populated from `/api/pipewire/aes67/instances` (receive mode only)
  - Auto-populates member name from device description on first selection
  - File: `www/pipewire-input-mixing.php` (RenderMember, RenderChannelMapping)

- [x] **2.3** Per-member gain control ✅
  - Volume stored in config (0-100) and applied via `channelmix.volume` on loopback playback.props
  - Real-time volume API: `POST /api/pipewire/audio/input-groups/volume`
  - Uses `pw-cli set-param <nodeId> Props { channelmix.volume: <linear> }` for live changes
  - Debounced UI slider (150ms) sends real-time changes without full restart
  - Also persists to config JSON for next restart
  - File: `www/api/controllers/pipewire.php` (`SetInputGroupMemberVolume()`)
  - Route: `www/api/index.php`

- [ ] **2.4** Source monitoring / metering (deferred — nice-to-have)
  - Optionally show input level meter (peak/RMS) per source
  - Use PipeWire peak detection or poll `pw-top` data
  - Not blocking

- [x] **2.5** Handle hot-plug / device removal ✅
  - WirePlumber handles graceful disconnect and auto-reconnection natively
  - UI shows device state badge: running (green), idle (yellow), disconnected (red)
  - "Refresh Sources" button for manual re-poll
  - Auto-refresh every 10 seconds to detect hot-plug events
  - Devices no longer in PipeWire show "disconnected" badge
  - File: `www/pipewire-input-mixing.php` (RefreshSources, auto-refresh timer)

### Implementation Notes (Phase 2)

- **Bug fix (pre-Phase 2):** stream.rules were matching input source names instead of
  output group sinks. Fixed to match `media.class=Audio/Sink` + `node.name=fpp_group_*`.
  Routing loopback modules (`fpp_route_ig*`) removed entirely — combine-stream handles
  output routing natively via stream.rules.
- **channelmix.volume** chosen over filter-chain gain — simpler, no extra nodes,
  PipeWire applies it in the channel mixing stage of the loopback
- **Channel mapping** uses `audio.position` on both capture and playback sides of the
  loopback module. PipeWire's channel mixer handles the upmix/downmix.
- **AES67 picker** loads instances from existing `/api/pipewire/aes67/instances` endpoint,
  filters to `mode=receive` only

### Testing Criteria

- [x] USB sound card line-in audio appears in output group mix
- [ ] Unplugging/replugging capture device recovers automatically
- [ ] Channel mapping (mono → stereo) works correctly
- [x] Multiple capture sources can be mixed simultaneously
- [ ] No audio glitches or xruns during normal operation
- [x] Real-time volume slider changes audible level without restart
- [ ] Device state badges update on hot-plug/removal

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

- [x] **3.1** Add `node.name` to GStreamer pipewiresink stream-properties
  - File: `src/mediaoutput/GStreamerOut.cpp`
  - Added `stream-properties` with `node.name=fppd_stream_1,node.description=FPP Media Stream 1`
  - All 3 pipeline paths updated: audio-only (parse_launch), video overlay, HDMI
  - Uses `GstStructure` for manual factory_make paths, inline for parse_launch

- [x] **3.2** Update `ApplyPipeWireAudioGroups()` routing logic
  - Already implemented in Phase 2: sets `PipeWireSinkName` to input group
  - When no input groups: continues to use output group (unchanged)

- [x] **3.3** Update AES67Manager recv pipeline naming
  - Verified: already sets `node.name=aes67_<instance>_recv` in recv pipeline
  - Names match what input group config references

- [x] **3.4** Update graph visualiser classification & virtual stream nodes
  - `classifyColumn()`: `fppd_stream_*` → column 0 (Input Sources)
  - Node description: "FPP Media Stream N"
  - **Virtual placeholders**: Graph API injects 5 fppd stream slot nodes;
    inactive slots appear greyed out with dashed borders
  - Virtual links show configured routing to input groups/output groups
  - Live nodes replace their virtual counterparts with full opacity
  - `nodeMetaText()` shows slot number, target routing, and active/inactive state
  - Input mixing UI: stream slot selector expanded to 5 options

- [x] **3.5** Update WirePlumber hook
  - Added `fppd_stream_*` pattern to blocking hook
  - Prevents rogue default-target links when input group isn't ready yet

### Testing Criteria

- [x] fppd audio output appears as "FPP Media Stream 1" (`fppd_stream_1`) in graph
- [x] Audio plays correctly through output group (no regression)
- [x] All existing playlist functionality works identically
- [ ] Volume control via `@DEFAULT_AUDIO_SINK@` still works
- [x] Graph visualiser correctly classifies the named stream

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

- [x] **4.1** Refactor `GStreamerOutput` to support multiple instances ✅
  - New `StreamSlotManager` singleton manages up to 5 simultaneous slots (`MAX_SLOTS = 5`)
  - Each instance gets: `fppd_stream_<N>`, its own GStreamer pipeline, its own `MediaOutputStatus`
  - `GStreamerOutput` constructor accepts `int streamSlot` parameter (default: 1)
  - Per-slot PipeWire sink names: slot 1 uses `PipeWireSinkName`, slots 2–5 use `PipeWireSinkName_<N>`
  - File: `src/mediaoutput/StreamSlotManager.h`, `src/mediaoutput/StreamSlotManager.cpp` (new)
  - File: `src/mediaoutput/GStreamerOut.h`, `src/mediaoutput/GStreamerOut.cpp`

- [x] **4.2** Add stream slot parameter to playlist media entries ✅
  - `PlaylistEntryMedia` gets `m_streamSlot` field (default: 1), `m_slotStatus` pointer
  - `GetStreamSlot()` and `IsBackgroundSlot()` helper methods
  - File: `src/playlist/PlaylistEntryMedia.h`, `src/playlist/PlaylistEntryMedia.cpp`
  - Playlist JSON: `{ "type": "media", "mediaName": "song.mp3", "streamSlot": 1 }`

- [x] **4.3** Per-stream volume control ✅
  - `StreamSlotManager::SetSlotVolume()` dispatches to active `GStreamerOutput::SetVolume()`
  - Slot 1 uses fppd's global volume; slots 2–5 go through `StreamSlotManager`
  - API: `POST /api/pipewire/audio/stream/volume` with `{ "slot": N, "volume": V }`
  - File: `www/api/controllers/pipewire.php`, `www/api/index.php`

- [x] **4.4** Multi-stream playlist scheduling ✅
  - `ProcessBackgroundSlots()` called from playlist process loop to manage concurrent slots
  - `StopAllSlots()` called during playlist stop for clean shutdown
  - Entries on different slots overlap (background music + announcements)
  - File: `src/playlist/Playlist.cpp`, `src/mediaoutput/StreamSlotManager.cpp`

- [x] **4.5** Update playlist editor UI ✅
  - `streamSlot` field added to both `media` and `both` entry types in `playlistEntryTypes.json`
  - Slot selector (1–5) per playlist entry
  - File: `www/playlistEntryTypes.json`

- [x] **4.6** WLED audio-reactive tap ✅
  - `static GStreamerOutput* m_currentInstance` tracks which slot is active for WLED
  - Slot 1 always takes priority for `m_currentInstance` (backward compatible)
  - Secondary slots only claim it if no other instance is active
  - On `Close()`, hands off to another active slot if available
  - `OnNewSample()` and `ProcessVideoOverlay()` use `m_currentInstance`
  - File: `src/mediaoutput/GStreamerOut.h`, `src/mediaoutput/GStreamerOut.cpp`

- [x] **4.7** Status API updates ✅
  - `GET /api/fppd/status` includes `streamSlots` array from `StreamSlotManager::GetAllSlotsStatus()`
  - Per-slot: song, elapsed time, state, active/inactive
  - Backward compatible: `current_song` field remains, reports stream 1
  - PHP endpoint: `GET /api/pipewire/audio/stream/status`
  - File: `src/httpAPI.cpp`, `www/api/controllers/pipewire.php`, `www/api/index.php`

### Implementation Notes (Phase 4)

- **StreamSlotManager** — new singleton in `src/mediaoutput/StreamSlotManager.{h,cpp}`,
  manages a fixed pool of 5 slots. Each slot tracks its active `GStreamerOutput*` and
  per-slot `MediaOutputStatus`. Thread-safe with `std::recursive_mutex`.
- **GStreamerOutput constructor** accepts `int streamSlot` (default 1). On `Start()`,
  registers with `StreamSlotManager`; on `Close()`, deregisters. Slot number determines
  PipeWire node name (`fppd_stream_1` … `fppd_stream_5`) and sink setting key.
- **m_currentInstance** — static pointer for WLED audio-reactive appsink tap. Slot 1
  always has priority. On close, ownership transfers to the next active slot.
- **playlistEntryTypes.json** — `streamSlot` field added to `media` (line 511) and
  `both` (line 788) entry types. Default value: 1.
- **Files added:** `StreamSlotManager.h`, `StreamSlotManager.cpp`
- **Files changed:** `GStreamerOut.h`, `GStreamerOut.cpp`, `PlaylistEntryMedia.h`,
  `PlaylistEntryMedia.cpp`, `Playlist.cpp`, `httpAPI.cpp`, `pipewire.php`, `index.php`,
  `playlistEntryTypes.json`

### Post-Implementation Bug Fixes

| Bug | Root Cause | Fix | Commit |
| --- | --- | --- | --- |
| **Self-deadlock in StreamSlotManager** | `std::mutex` couldn't handle re-entrant calls from `Start()`→`Stop()` within the same lock | Changed to `std::recursive_mutex` | `0b764171` |
| **`Stop()` deadlock** | Appsink/bus callbacks held the lock while `gst_element_set_state(NULL)` tried to drain them | Clean up appsink and bus *before* state change | `370dfa57` |
| **PipeWire audio groups not robust across reboots** | USB card names changed across reboots, breaking input group config references | Boot-time card resolution + regeneration script for `96-fpp-input-groups.conf` | `3675b7a6` |
| **Input group mute/volume not working in real-time** | `ToggleMute()` didn't send API call; mute flag didn't persist correctly | Send `POST /api/pipewire/audio/input-groups/volume` with `mute` flag; preserve saved volume on mute | `4da04d7a` |
| **Routing matrix page missing header** | Used `common/menuBody.inc` instead of FPP standard `menu.inc` pattern; `AudioPipeWire` setting check was wrong | Restructured to standard FPP page layout; changed check to `AudioBackend === 'pipewire'` | `7bda3e18` |
| **Graph showed fppd stream routing to wrong input group** | `$fppdStreamTargets` map was single-value, last group overwrote previous | Changed to array-of-targets so all group assignments are preserved | `40819271` |
| **Songs skipping without playing (5s stall)** | Same overwrite bug in `ApplyPipeWireAudioGroups` and `ApplyPipeWireInputGroupsConfig` — last input group wrote `PipeWireSinkName` to a non-existent node | First-wins semantics: `if (!isset($slotTargets[$slotNum]))` | `e00bb6e1` |
| **Volume/mute API returned "loopback node not found"** | PipeWire loopback modules create only `input.NAME` and `output.NAME` sub-nodes (no bare parent) | Match all three name variants: bare, `input.` prefix, `output.` prefix | `54eb4abf` |

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

**Goal:** Full routing matrix, per-path volume/mute, input group effects, presets, live mixing.

### Design

```
Input Groups  ─── [volume/mute per path] ──→  Output Groups
     │                                              │
     └── (optional EQ) ──→ routing hub ─────────────┘
```

**Architecture with effects:**
```
Sources → combine-stream(fpp_input_<name>) → filter-chain(fpp_fx_ig_<id>)
  → combine-stream(fpp_route_ig_<id>, stream.rules with channelmix.volume) → output groups
```

**Architecture without effects (direct routing):**
```
Sources → combine-stream(fpp_input_<name>, stream.rules with channelmix.volume) → output groups
```

### Tasks

- [x] **5.1** Routing matrix UI (`pipewire-routing-matrix.php`)
  - Grid view: input groups (rows) × output groups (columns)
  - Per-cell: checkbox (connected), volume slider, mute button
  - Settings menu entry with modal support
  - Link from input-mixing page to routing matrix

- [x] **5.2** Per-routing-path volume
  - `channelmix.volume` in combine-stream `stream.rules` `create-stream` action
  - Per-path `routing` config in input groups JSON: `{ "<ogId>": { volume, mute } }`
  - Real-time adjustment via `pw-cli set-param` on internal combine-stream nodes
  - API: `POST /api/pipewire/audio/routing/volume`
  - Muted paths skipped during config generation

- [x] **5.3** Input group effects (EQ)
  - Optional `filter-chain` module with biquad EQ per input group
  - Config schema: `ig.effects.eq.enabled`, `ig.effects.eq.bands[]`
  - When effects enabled: combine-stream → filter-chain → routing combine-stream → output groups
  - When effects disabled: combine-stream → output groups (direct)
  - Real-time EQ adjustment via `pw-cli set-param`
  - API: `POST /api/pipewire/audio/input-groups/effects` (save)
  - API: `POST /api/pipewire/audio/input-groups/eq/update` (real-time)
  - WirePlumber hook updated for `fpp_fx_ig_*` and `fpp_route_ig_*` patterns

- [x] **5.4** Group templates / presets
  - Save/load routing configurations (outputs, routing, effects)
  - Stored in `<media>/config/routing-presets/<name>.json`
  - API: `GET/POST /api/pipewire/audio/routing/presets`
  - API: `POST /api/pipewire/audio/routing/presets/load`
  - API: `DELETE /api/pipewire/audio/routing/presets/:name`
  - UI integrated into routing matrix page

- [x] **5.5** Live mixing controls
  - Real-time per-path volume via debounced slider → `pw-cli set-param`
  - Real-time EQ band adjustment (freq/gain/Q) without Save & Apply
  - Dirty indicator shows unsaved changes
  - Save & Apply button regenerates PipeWire config and restarts

### Testing Criteria

- [x] Routing matrix shows all input group × output group combinations
- [ ] Per-path volume slider adjusts volume in real time
- [ ] Mute button silences specific routing paths
- [ ] EQ can be enabled per input group with real-time band adjustment
- [ ] Saving a preset captures all routing + effects configuration
- [ ] Loading a preset restores routing and effects
- [ ] Save & Apply regenerates PipeWire config with per-path volumes
- [ ] Graph visualizer shows routing hub and EQ nodes when effects are active

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

### Phase 2 — ALSA Capture Routing ✅ IMPLEMENTED
- [x] 2.1 Capture device enumeration API (implemented in Phase 1)
- [x] 2.2 Source device picker UI + channel mapping + AES67 picker
- [x] 2.3 Per-member gain control (channelmix.volume + real-time API)
- [ ] 2.4 Source metering (deferred — nice-to-have)
- [x] 2.5 Hot-plug handling (state badges + auto-refresh)
- [ ] Phase 2 testing complete

### Phase 3 — fppd Stream Naming ✅ IMPLEMENTED
- [x] 3.1 Add node.name to GStreamer pipewiresink
- [x] 3.2 Update routing logic for input groups (done in Phase 2)
- [x] 3.3 AES67 recv naming verification
- [x] 3.4 Visualiser classification + virtual stream placeholders
- [x] 3.5 WirePlumber hook update
- [ ] Phase 3 testing complete

### Phase 4 — Multiple fppd Streams ✅ IMPLEMENTED
- [x] 4.1 GStreamerOutput multi-instance refactor (StreamSlotManager + per-slot PipeWire nodes)
- [x] 4.2 Playlist slot parameter (streamSlot 1-5 in PlaylistEntryMedia)
- [x] 4.3 Per-stream volume control (PHP API + pw-cli set-param)
- [x] 4.4 Multi-stream scheduling (ProcessBackgroundSlots + StopAllSlots)
- [x] 4.5 Playlist editor UI (streamSlot field in playlistEntryTypes.json)
- [x] 4.6 WLED audio-reactive tap (slot 1 priority for m_currentInstance)
- [x] 4.7 Status API updates (streamSlots in fppd status + PHP status endpoint)
- [ ] Phase 4 testing complete

### Phase 5 — Advanced Routing ✅ IMPLEMENTED
- [x] 5.1 Routing matrix UI
- [x] 5.2 Per-routing-path volume
- [x] 5.3 Input group effects
- [x] 5.4 Group templates / presets
- [x] 5.5 Live mixing controls
- [ ] Phase 5 testing complete

### Post-Implementation Bug Fixes ✅
- [x] StreamSlotManager self-deadlock (`recursive_mutex`) — `0b764171`
- [x] `Stop()` deadlock (appsink/bus cleanup before state change) — `370dfa57`
- [x] Boot robustness (USB card resolution + regeneration) — `3675b7a6`
- [x] Mute/volume real-time control + persistence — `4da04d7a`
- [x] Routing matrix page structure + AudioBackend check — `7bda3e18`
- [x] Graph multi-target (fppd stream → multiple input groups) — `40819271`
- [x] PipeWireSinkName first-wins overwrite fix — `e00bb6e1`
- [x] Loopback sub-node matching (`input.`/`output.` prefixes) — `54eb4abf`
