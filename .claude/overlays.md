# Pixel Overlay System (`src/overlays/`)

## Model Hierarchy

- **`PixelOverlayModel`** — Base class. Represents a 2D pixel grid mapped to DMX channels. Manages shared memory buffers (`shm_open`) for external process access. Supports horizontal/vertical orientation, start corner configuration, and custom node mapping.
- **`PixelOverlayModelFB`** — Framebuffer-based model for hardware displays. Copies data to framebuffer on overlay.
- **`PixelOverlayModelSub`** — Sub-model (child) with X/Y offset within a parent model. Delegates rendering to parent.

## Overlay States

| State | Value | Behavior |
| --- | --- | --- |
| Disabled | 0 | No overlay rendering |
| Enabled | 1 | Opaque — direct channel copy |
| Transparent | 2 | Only non-zero channels overlay |
| TransparentRGB | 3 | Only if all RGB channels non-zero |

## PixelOverlayManager (Singleton)

Manages all models (loaded from `config/model-overlays.json`), state transitions, HTTP API endpoints, font discovery, and periodic effect update thread. Called each frame via `doOverlays()` to blend overlay data into the channel buffer.

## Effect System

- **`RunningEffect`** — Abstract base for active effect instances. `update()` returns ms until next update (0 = done). One effect per model.
- **`PixelOverlayEffect`** — Abstract base extending `Command`. Registry via `GetPixelOverlayEffect(name)`. Static add/remove/list methods.

### Built-in Effects (`PixelOverlayEffects.cpp`)

| Effect | Description |
| --- | --- |
| Color Fade | Fade in/out solid color with configurable timing |
| Bars | Animated color bars (Up/Down/Left/Right) |
| Text | Text rendering with scrolling, stationary, or centered modes |
| Stop Effects | Stop running effect, optionally auto-disable model |

## WLED Effects Port (`src/overlays/wled/`)

A port of the WLED firmware's effect engine providing 217 effect modes. Integrated via `WLEDEffect` / `WLEDRunningEffect` / `WS2812FXExt` wrapper classes.

**Effect Parameters**: Buffer mapping, Brightness, Speed, Intensity, Custom1/2/3, Check1/2/3 (bool), Palette, Color1/2/3, Text.

**Effect Categories** (217 total modes defined in `FX.h`):

- **Classic 1D** (~80 effects): Blink, Breath, Chase variants, Comet, Dissolve, Drip, Fade, Fire variants, Fireworks, Glitter, Larson Scanner, Lightning, Meteor, Popcorn, Rainbow, Ripple, Scan, Sparkle, Strobe, Twinkle variants, Wipe variants
- **Noise/Math** (~20 effects): BPM, Colorwaves, Juggle, Noise 16 variants, Oscillate, Palette, Plasma, Pride 2015, Sinelon variants
- **2D Effects** (~30 effects): Akemi, Black Hole, Colored Bursts, DNA, Drift, Fire Noise, Frizzles, Game of Life, GEQ, Julia, Lissajous, Matrix, Metaballs, Noise, Octopus, Plasma Rotozoom, Polar Lights, Pulser, Scrolling Text, Squared Swirl, Sun Radiation, Swirl, Tartan, Waverly
- **Audio-Reactive** (marked with music note, ~20 effects): DJ Light, Freq Map/Matrix/Pixels/Wave, Grav Center/Centric/Freq, Gravimeter, Mid Noise, Noise Meter, Waterfall
- **Particle System** (~30 effects): Attractor, Blobs, Box, Center GEQ, Dancing Shadows, Drip, Fire, Fireworks, GEQ, Ghost Rider, Hourglass, Impact, Perlin, Pinball, Pit, Sonic Boom/Stream, Sparkler, Spray, Starburst, Volcano, Vortex, Waterfall

**WLED Source Files** (60+ files in `wled/`): `FX.h` (effect definitions), `FX.cpp` (1D implementations), `FX_2Dfcn.cpp` (2D implementations), `FXparticleSystem.h/cpp` (particle effects), plus color utilities, math, FFT, palettes, fonts.
