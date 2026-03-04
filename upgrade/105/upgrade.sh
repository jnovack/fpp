#!/bin/bash
#####################################
# Upgrade 105: Install GStreamer + PipeWire audio stack
#
# Sets up the complete audio stack required by the GStreamer-based
# media engine:
#   - PipeWire (audio graph, combine-streams, filter-chains)
#   - WirePlumber (session manager, ALSA monitor, link policy)
#   - pipewire-pulse (PulseAudio compat for wpctl / volume control)
#   - GStreamer 1.x (media playback, AES67 RTP, HDMI video)
#   - linuxptp (PTP clock for AES67)
#   - pulseaudio-utils (pactl for diagnostics)
#
# Idempotent — safe to run multiple times. Skips packages already
# installed and config files already in place.
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

echo "FPP - Upgrade 105: Install GStreamer + PipeWire audio stack"
echo "==========================================================="

FPPPLATFORM=$(cat /etc/fpp/platform 2>/dev/null)

# Only run on Linux platforms (skip macOS)
if [ "${FPPPLATFORM}" == "MacOS" ]; then
    echo "  Skipping — not supported on macOS"
    exit 0
fi

#######################################
# 1. Install required packages
#######################################
echo ""
echo "Step 1: Checking required packages..."

# Core PipeWire + WirePlumber stack
REQUIRED_PKGS="
    pipewire
    pipewire-bin
    pipewire-alsa
    pipewire-pulse
    pipewire-jack
    pipewire-audio-client-libraries
    wireplumber
    libpipewire-0.3-dev
    pulseaudio-utils
    linuxptp
"

# GStreamer core + plugins needed by FPP
#   plugins-base: audioconvert, audioresample, volume, appsink, capsfilter, tee, queue
#   plugins-good: autoaudiosink, udpsink, rtpL24pay, multiudpsink
#   plugins-bad:  kmssink (HDMI output), pipewiresrc/pipewiresink
#   gstreamer1.0-pipewire: pipewiresrc, pipewiresink elements
#   gstreamer1.0-libav: broad codec support (mp3, aac, h264, etc.)
#   libgstreamer1.0-dev: compile-time headers for fppd
#   libgstreamer-plugins-base1.0-dev: appsink headers
REQUIRED_PKGS="${REQUIRED_PKGS}
    gstreamer1.0-tools
    gstreamer1.0-plugins-base
    gstreamer1.0-plugins-good
    gstreamer1.0-plugins-bad
    gstreamer1.0-plugins-ugly
    gstreamer1.0-pipewire
    gstreamer1.0-libav
    libgstreamer1.0-dev
    libgstreamer-plugins-base1.0-dev
"

# Optional: GL support for video rendering (may not be present on all platforms)
# These are best-effort — don't fail upgrade if unavailable
OPTIONAL_PKGS="
    gstreamer1.0-gl
    gstreamer1.0-x
    libgstreamer-plugins-bad1.0-0
"

PKGS_NEEDED=""
for pkg in ${REQUIRED_PKGS}; do
    dpkg -l ${pkg} 2>/dev/null | grep -q '^ii' || PKGS_NEEDED="${PKGS_NEEDED} ${pkg}"
done

if [ -n "${PKGS_NEEDED}" ]; then
    echo "  Installing required packages:${PKGS_NEEDED}"
    apt-get update -q
    apt-get -y -q -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" install ${PKGS_NEEDED}
    if [ $? -ne 0 ]; then
        echo "  WARNING: Some required packages failed to install. Audio may not work correctly."
    fi
else
    echo "  All required packages already installed."
fi

# Install optional packages (ignore failures)
OPTS_NEEDED=""
for pkg in ${OPTIONAL_PKGS}; do
    dpkg -l ${pkg} 2>/dev/null | grep -q '^ii' || OPTS_NEEDED="${OPTS_NEEDED} ${pkg}"
done
if [ -n "${OPTS_NEEDED}" ]; then
    echo "  Installing optional packages (best-effort):${OPTS_NEEDED}"
    apt-get -y -q install ${OPTS_NEEDED} 2>/dev/null || true
fi

#######################################
# 2. PipeWire base configuration
#######################################
echo ""
echo "Step 2: Setting up PipeWire base configuration..."
mkdir -p /etc/pipewire /etc/pipewire/pipewire.conf.d

# Copy stock PipeWire configs if not already present
for conf in pipewire.conf pipewire-pulse.conf client.conf; do
    if [ ! -f "/etc/pipewire/${conf}" ] && [ -f "/usr/share/pipewire/${conf}" ]; then
        cp "/usr/share/pipewire/${conf}" "/etc/pipewire/${conf}"
        echo "    Copied ${conf}"
    fi
done

# Deploy FPP PipeWire config overlay (48kHz, quantum 256, RT priority)
if [ -d /opt/fpp/etc/pipewire/pipewire.conf.d ]; then
    cp -a /opt/fpp/etc/pipewire/pipewire.conf.d/. /etc/pipewire/pipewire.conf.d/
    echo "    Deployed FPP PipeWire config overlay"
fi

#######################################
# 3. WirePlumber configuration
#######################################
echo ""
echo "Step 3: Setting up WirePlumber configuration..."
mkdir -p /etc/wireplumber/wireplumber.conf.d

if [ -d /opt/fpp/etc/wireplumber/wireplumber.conf.d ]; then
    cp -a /opt/fpp/etc/wireplumber/wireplumber.conf.d/. /etc/wireplumber/wireplumber.conf.d/
    echo "    Deployed FPP WirePlumber config (systemwide session, no ALSA reservation)"
fi

# Remove old WirePlumber 0.4 Lua configs (not supported by WirePlumber 0.5+)
if [ -d /etc/wireplumber/main.lua.d ]; then
    echo "    Removing old WirePlumber 0.4 Lua configs..."
    rm -rf /etc/wireplumber/main.lua.d
fi

#######################################
# 4. systemd service files
#######################################
echo ""
echo "Step 4: Installing systemd service files..."
for svc in fpp-pipewire.service fpp-wireplumber.service fpp-pipewire-pulse.service; do
    if [ -f "/opt/fpp/etc/systemd/${svc}" ]; then
        cp "/opt/fpp/etc/systemd/${svc}" /lib/systemd/system/
        echo "    Installed ${svc}"
    fi
done

# Also refresh fppd.service (picks up /run/fppd/fpp-audio.env EnvironmentFile)
if [ -f "/opt/fpp/etc/systemd/fppd.service" ]; then
    cp /opt/fpp/etc/systemd/fppd.service /lib/systemd/system/
    echo "    Updated fppd.service"
fi

systemctl daemon-reload
systemctl enable fpp-pipewire.service
systemctl enable fpp-wireplumber.service
systemctl enable fpp-pipewire-pulse.service

#######################################
# 5. Mask user-session PipeWire services
#######################################
echo ""
echo "Step 5: Masking user-session PipeWire services (prevents conflicts)..."
mkdir -p /home/fpp/.config/systemd/user
for svc in pipewire.socket pipewire.service pipewire-pulse.service pipewire-pulse.socket wireplumber.service; do
    ln -sf /dev/null "/home/fpp/.config/systemd/user/${svc}"
done
chown -R fpp:fpp /home/fpp/.config
echo "    User-session PipeWire services masked for fpp user"

#######################################
# 6. Runtime directory
#######################################
echo ""
echo "Step 6: Ensuring PipeWire runtime directory exists..."
mkdir -p /run/pipewire-fpp/pulse
chmod 755 /run/pipewire-fpp
chmod 755 /run/pipewire-fpp/pulse

#######################################
# 7. Restart PipeWire services
#######################################
echo ""
echo "Step 7: (Re)starting PipeWire services..."
# Stop in reverse order, start in dependency order
systemctl stop fpp-pipewire-pulse.service 2>/dev/null || true
systemctl stop fpp-wireplumber.service 2>/dev/null || true
systemctl stop fpp-pipewire.service 2>/dev/null || true
sleep 1

systemctl start fpp-pipewire.service
sleep 2
systemctl start fpp-wireplumber.service
sleep 3
systemctl start fpp-pipewire-pulse.service
sleep 1

#######################################
# 8. Verify
#######################################
echo ""
echo "Step 8: Verifying service status..."
ALL_OK=true
for svc in fpp-pipewire fpp-wireplumber fpp-pipewire-pulse; do
    STATUS=$(systemctl is-active ${svc}.service 2>/dev/null)
    if [ "${STATUS}" == "active" ]; then
        echo "    ${svc}: active"
    else
        echo "    ${svc}: ${STATUS} (PROBLEM)"
        ALL_OK=false
    fi
done

# Verify key GStreamer elements are available
echo ""
echo "  Checking GStreamer elements..."
GST_OK=true
for element in pipewiresink pipewiresrc audioconvert audioresample volume appsink decodebin; do
    if gst-inspect-1.0 ${element} &>/dev/null; then
        echo "    ${element}: OK"
    else
        echo "    ${element}: MISSING"
        GST_OK=false
    fi
done

echo ""
if ${ALL_OK} && ${GST_OK}; then
    echo "Upgrade 105 complete — GStreamer + PipeWire audio stack is ready."
else
    if ! ${ALL_OK}; then
        echo "WARNING: Some PipeWire services are not running. Check: systemctl status fpp-pipewire fpp-wireplumber fpp-pipewire-pulse"
    fi
    if ! ${GST_OK}; then
        echo "WARNING: Some GStreamer elements are missing. Media playback or AES67 may not work."
    fi
fi
