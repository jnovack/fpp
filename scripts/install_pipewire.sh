#!/bin/bash
#####################################
# install_pipewire.sh
#
# Standalone script to install/repair PipeWire configuration on
# existing FPP systems. This is idempotent and safe to run multiple
# times. It performs the same steps as upgrade/105/upgrade.sh but
# can be invoked manually at any time.
#
# Usage:  sudo /opt/fpp/scripts/install_pipewire.sh
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/common
. ${BINDIR}/functions

echo "FPP - Installing/repairing PipeWire configuration"
echo "=================================================="

FPPPLATFORM=$(cat /etc/fpp/platform 2>/dev/null)

if [ "${FPPPLATFORM}" == "MacOS" ]; then
    echo "PipeWire is not supported on macOS."
    exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (sudo)."
    exit 1
fi

# --- 1. Ensure packages are installed ---
echo ""
echo "Step 1: Checking required packages..."
PKGS_NEEDED=""
dpkg -l pipewire 2>/dev/null | grep -q '^ii' || PKGS_NEEDED="${PKGS_NEEDED} pipewire"
dpkg -l pipewire-pulse 2>/dev/null | grep -q '^ii' || PKGS_NEEDED="${PKGS_NEEDED} pipewire-pulse"
dpkg -l wireplumber 2>/dev/null | grep -q '^ii' || PKGS_NEEDED="${PKGS_NEEDED} wireplumber"
dpkg -l pulseaudio-utils 2>/dev/null | grep -q '^ii' || PKGS_NEEDED="${PKGS_NEEDED} pulseaudio-utils"

if [ -n "${PKGS_NEEDED}" ]; then
    echo "  Installing:${PKGS_NEEDED}"
    apt-get -y -q install ${PKGS_NEEDED}
else
    echo "  All packages already installed."
fi

# --- 2. PipeWire base configuration ---
echo ""
echo "Step 2: Setting up PipeWire base configuration..."
mkdir -p /etc/pipewire /etc/pipewire/pipewire.conf.d

for conf in pipewire.conf pipewire-pulse.conf client.conf; do
    if [ ! -f "/etc/pipewire/${conf}" ] && [ -f "/usr/share/pipewire/${conf}" ]; then
        cp "/usr/share/pipewire/${conf}" "/etc/pipewire/${conf}"
        echo "  Copied ${conf}"
    else
        echo "  ${conf} already exists."
    fi
done

# --- 3. FPP PipeWire config overlay ---
echo ""
echo "Step 3: Deploying FPP PipeWire configuration overlay..."
if [ -d /opt/fpp/etc/pipewire/pipewire.conf.d ]; then
    cp -a /opt/fpp/etc/pipewire/pipewire.conf.d/. /etc/pipewire/pipewire.conf.d/
    echo "  Copied FPP PipeWire config to /etc/pipewire/pipewire.conf.d/"
else
    echo "  WARNING: /opt/fpp/etc/pipewire/pipewire.conf.d not found in repo!"
fi

# --- 4. WirePlumber configuration ---
echo ""
echo "Step 4: Setting up WirePlumber configuration..."
mkdir -p /etc/wireplumber/wireplumber.conf.d

if [ -d /opt/fpp/etc/wireplumber/wireplumber.conf.d ]; then
    cp -a /opt/fpp/etc/wireplumber/wireplumber.conf.d/. /etc/wireplumber/wireplumber.conf.d/
    echo "  Copied FPP WirePlumber config to /etc/wireplumber/wireplumber.conf.d/"
else
    echo "  WARNING: /opt/fpp/etc/wireplumber/wireplumber.conf.d not found in repo!"
fi

# Remove old WirePlumber 0.4 Lua configs (not supported by WirePlumber 0.5+)
if [ -d /etc/wireplumber/main.lua.d ]; then
    echo "  Removing old WirePlumber Lua configs from /etc/wireplumber/main.lua.d/"
    rm -rf /etc/wireplumber/main.lua.d
fi

# --- 5. systemd service files ---
echo ""
echo "Step 5: Installing systemd service files..."
for svc in fpp-pipewire.service fpp-wireplumber.service fpp-pipewire-pulse.service; do
    if [ -f "/opt/fpp/etc/systemd/${svc}" ]; then
        cp "/opt/fpp/etc/systemd/${svc}" /lib/systemd/system/
        echo "  Installed ${svc}"
    else
        echo "  WARNING: /opt/fpp/etc/systemd/${svc} not found!"
    fi
done

systemctl daemon-reload
systemctl enable fpp-pipewire.service
systemctl enable fpp-wireplumber.service
systemctl enable fpp-pipewire-pulse.service
echo "  Services enabled."

# --- 6. Fix user PipeWire masking symlinks ---
echo ""
echo "Step 6: Masking user-session PipeWire services..."
mkdir -p /home/fpp/.config/systemd/user
for svc in pipewire.socket pipewire.service pipewire-pulse.service pipewire-pulse.socket wireplumber.service; do
    ln -sf /dev/null "/home/fpp/.config/systemd/user/${svc}"
done
chown -R fpp:fpp /home/fpp/.config
echo "  User-session PipeWire services masked."

# --- 7. Create runtime directory ---
echo ""
echo "Step 7: Ensuring PipeWire runtime directory exists..."
mkdir -p /run/pipewire-fpp/pulse
chmod 755 /run/pipewire-fpp
chmod 755 /run/pipewire-fpp/pulse
echo "  /run/pipewire-fpp ready."

# --- 8. Restart PipeWire services ---
echo ""
echo "Step 8: Starting PipeWire services..."
systemctl restart fpp-pipewire.service
sleep 2
systemctl restart fpp-wireplumber.service
sleep 3
systemctl restart fpp-pipewire-pulse.service
sleep 1

# --- 9. Verify ---
echo ""
echo "Step 9: Verifying PipeWire status..."

PW_OK=true
for svc in fpp-pipewire fpp-wireplumber fpp-pipewire-pulse; do
    STATUS=$(systemctl is-active ${svc}.service 2>/dev/null)
    if [ "${STATUS}" == "active" ]; then
        echo "  ${svc}: active"
    else
        echo "  ${svc}: ${STATUS} (PROBLEM)"
        PW_OK=false
    fi
done

echo ""
if ${PW_OK}; then
    echo "PipeWire installation complete and all services running."
    echo ""
    echo "To verify audio sinks, run:"
    echo "  PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp pactl list sinks short"
    echo ""
    echo "If the AudioBackend setting is not yet set to 'pipewire', update it via"
    echo "the FPP web UI under Status/Control > FPP Settings > Audio."
else
    echo "WARNING: Some PipeWire services are not running."
    echo "Check logs with:  journalctl -u fpp-pipewire -u fpp-wireplumber -u fpp-pipewire-pulse --no-pager -n 50"
fi
