#!/bin/bash
#####################################
# Upgrade 105: Fix PipeWire/WirePlumber configuration
# - Migrate WirePlumber from old 0.4 Lua config to 0.5 .conf format
# - Install missing PipeWire base config files
# - Install pulseaudio-utils if missing (provides pactl)
# - Fix broken user-session PipeWire masking symlinks
# - Re-deploy systemd service files
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/../../scripts/common

# Install drogon framework, replacing libhttpserver as the HTTP server backend.
# libdrogon-dev is apt-installable; libtrantor-dev is pulled in as a dependency.
if [ "${FPPPLATFORM}" != "MacOS" ]; then
    if ! dpkg -l | grep -q libdrogon-dev; then
        echo "FPP - Installing libdrogon-dev (replaces libhttpserver)"
        apt-get update
        apt-get install -y libdrogon-dev
    fi
    
    # Remove real libhttpserver shared libraries to prevent symbol conflicts
    # with FPP's compatibility shim in libfpp.so. Plugins that still link
    # -lhttpserver would load the real library, causing a SEGV when the
    # shim's webserver object is passed to the real register_resource().
    rm -f /lib/libhttpserver.so* /usr/lib/libhttpserver.so* /usr/local/lib/libhttpserver.so*
fi

echo "FPP - Upgrading PipeWire/WirePlumber configuration"

FPPPLATFORM=$(cat /etc/fpp/platform 2>/dev/null)

# Only run on Linux platforms (skip macOS)
if [ "${FPPPLATFORM}" == "MacOS" ]; then
    echo "  Skipping PipeWire setup on macOS"
    exit 0
fi

# 1. Install pulseaudio-utils if missing (provides pactl for volume control)
if ! command -v pactl &>/dev/null; then
    echo "  Installing pulseaudio-utils..."
    apt-get -y -q install pulseaudio-utils
fi

# 2. Copy base PipeWire config files if missing
echo "  Ensuring PipeWire base configuration files exist..."
mkdir -p /etc/pipewire /etc/pipewire/pipewire.conf.d
for conf in pipewire.conf pipewire-pulse.conf client.conf; do
    if [ ! -f "/etc/pipewire/${conf}" ] && [ -f "/usr/share/pipewire/${conf}" ]; then
        cp "/usr/share/pipewire/${conf}" "/etc/pipewire/${conf}"
        echo "    Copied ${conf}"
    fi
done

# 3. Deploy FPP PipeWire config overlay
echo "  Deploying FPP PipeWire configuration..."
cp -a /opt/fpp/etc/pipewire/pipewire.conf.d/. /etc/pipewire/pipewire.conf.d/

# 4. Migrate WirePlumber from old Lua config to new .conf format
echo "  Migrating WirePlumber configuration to 0.5 format..."
mkdir -p /etc/wireplumber/wireplumber.conf.d
cp -a /opt/fpp/etc/wireplumber/wireplumber.conf.d/. /etc/wireplumber/wireplumber.conf.d/

# Remove old WirePlumber 0.4 Lua configs (no longer supported in WirePlumber 0.5+)
if [ -d /etc/wireplumber/main.lua.d ]; then
    echo "    Removing old WirePlumber Lua configs..."
    rm -rf /etc/wireplumber/main.lua.d
fi

# 5. Re-deploy systemd service files
echo "  Updating systemd service files..."
cp /opt/fpp/etc/systemd/fpp-pipewire.service /lib/systemd/system/
cp /opt/fpp/etc/systemd/fpp-wireplumber.service /lib/systemd/system/
cp /opt/fpp/etc/systemd/fpp-pipewire-pulse.service /lib/systemd/system/
systemctl daemon-reload
systemctl enable fpp-pipewire.service
systemctl enable fpp-wireplumber.service
systemctl enable fpp-pipewire-pulse.service

# 6. Fix broken symlinks for user-session PipeWire masking
if [ -d /home/fpp/.config/systemd/user ]; then
    echo "  Fixing user-session PipeWire masking symlinks..."
    for svc in pipewire.socket pipewire.service pipewire-pulse.service pipewire-pulse.socket wireplumber.service; do
        target="/home/fpp/.config/systemd/user/${svc}"
        # Remove if broken symlink or pointing to wrong target
        if [ -L "${target}" ] && [ "$(readlink "${target}")" != "/dev/null" ]; then
            rm -f "${target}"
            ln -sf /dev/null "${target}"
        elif [ ! -e "${target}" ]; then
            ln -sf /dev/null "${target}"
        fi
    done
    chown -R fpp:fpp /home/fpp/.config
fi

# 7. Restart PipeWire services if they are currently enabled
if systemctl is-enabled fpp-pipewire.service &>/dev/null; then
    echo "  Restarting PipeWire services..."
    systemctl restart fpp-pipewire.service
    sleep 2
    systemctl restart fpp-wireplumber.service
    sleep 3
    systemctl restart fpp-pipewire-pulse.service
    sleep 1
fi

echo "  PipeWire upgrade complete."
