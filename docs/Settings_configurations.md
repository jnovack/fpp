# Settings Definitions Explained

## Setting defs, storage and initialisation

FPP settings are defined in www/settings.json and for some default values are define in this file

The config setup is done by config.php which uses a mixture of logic, already saved settings and default values to create a php 'settings' array variable.

The local instance of fpp stores its current setting configuration in:  /home/fpp/media/settings

## Audio Backend

`AudioBackend` (General -> Audio) controls whether Falcon Player opens the audio hardware directly through ALSA (`Direct ALSA`) or routes playback through the PipeWire stack (`PipeWire`).

- **Direct ALSA (default)** keeps the legacy `.asoundrc` pipeline that hands audio straight to the selected card. Choose this when you rely on the existing Audio Output drop-down to pick a specific device or when PipeWire is not required.
- **PipeWire** starts the `fpp-pipewire`, `fpp-wireplumber`, and `fpp-pipewire-pulse` services, rewrites `.asoundrc` to use the PipeWire ALSA bridge, and exposes a PulseAudio-compatible server for SDL clients. PipeWire adds software mixing with predictable latency (tuned in `etc/pipewire/pipewire.conf.d/90-fpp.conf`). The default sink follows WirePlumber policy; use `wpctl status` / `wpctl set-default <sink-id>` to steer audio to a specific device, or switch back to ALSA if you need per-card routing from the UI.

Changing the backend triggers `fppinit setupAudio`, so no reboot is required, but restart any active playback to pick up the new audio stack.
