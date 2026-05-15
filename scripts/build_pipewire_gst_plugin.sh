#!/bin/bash
#####################################
# build_pipewire_gst_plugin.sh
#
# Build and install the PipeWire GStreamer plugin from source.
#
# The stock Debian Trixie gstreamer1.0-pipewire package (1.4.2) ships a
# GStreamer plugin that crashes in on_remove_buffer when consumers
# disconnect from a pipewiresink running in mode=provide.  This bug
# blocks persistent Video/Source nodes — the core of FPP's video
# input-source architecture.
#
# The fix is present in upstream PipeWire >= 1.6.0 (commit range around
# the mode=provide buffer lifecycle guard in gstpipewiresink.c).  This
# script builds ONLY the GStreamer plugin from PipeWire source and drops
# it in place of the stock .so, leaving the PipeWire daemon, libraries,
# and WirePlumber at their distro versions.
#
# The stock plugin is backed up as libgstpipewire.so.bak-<version>.
#
# Usage:
#   sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh [TAG]
#
# TAG defaults to 1.4.2 (the current distro version) — override with
# any PipeWire git tag, branch, or commit hash, e.g.:
#   sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh 1.6.0
#   sudo /opt/fpp/scripts/build_pipewire_gst_plugin.sh master
#
# Requires internet access to clone the PipeWire git repository.
#####################################

set -euo pipefail

PIPEWIRE_TAG="${1:-1.4.2}"
PIPEWIRE_REPO="https://gitlab.freedesktop.org/pipewire/pipewire.git"
BUILD_DIR="/tmp/pipewire-gst-build"
GST_PLUGIN_DIR=$(pkg-config --variable=pluginsdir gstreamer-1.0 2>/dev/null || echo "/usr/lib/arm-linux-gnueabihf/gstreamer-1.0")
STOCK_VERSION=$(dpkg-query -W -f='${Version}' gstreamer1.0-pipewire 2>/dev/null | cut -d- -f1 || echo "unknown")

echo "============================================"
echo "FPP — Build PipeWire GStreamer Plugin"
echo "============================================"
echo "  Target PipeWire tag : ${PIPEWIRE_TAG}"
echo "  GStreamer plugin dir: ${GST_PLUGIN_DIR}"
echo "  Stock plugin version: ${STOCK_VERSION}"
echo ""

# --- Root check ---
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (sudo)."
    exit 1
fi

# --- 1. Install build dependencies ---
echo "Step 1: Installing build dependencies..."
apt-get update -q
apt-get install -y -q \
    meson \
    ninja-build \
    git \
    pkg-config \
    libpipewire-0.3-dev \
    libspa-0.2-dev \
    libgstreamer1.0-dev \
    libgstreamer-plugins-base1.0-dev
echo "  Done."

# --- 2. Clone PipeWire source ---
echo ""
echo "Step 2: Cloning PipeWire source (tag: ${PIPEWIRE_TAG})..."
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"
cd "${BUILD_DIR}"
git clone --depth 1 --branch "${PIPEWIRE_TAG}" "${PIPEWIRE_REPO}" pipewire 2>&1 || {
    echo "  Tag '${PIPEWIRE_TAG}' not found — trying as branch/commit..."
    rm -rf pipewire
    git clone "${PIPEWIRE_REPO}" pipewire
    cd pipewire
    git checkout "${PIPEWIRE_TAG}"
    cd ..
}
echo "  Source ready at ${BUILD_DIR}/pipewire"

# --- 3. Configure meson (minimal — GStreamer plugin only) ---
echo ""
echo "Step 3: Configuring meson build..."
cd "${BUILD_DIR}/pipewire"

# Disable everything except the GStreamer plugin to minimize build time.
# Options vary by PipeWire version; we use -Dauto_features=disabled to
# turn off everything, then selectively enable what we need.
meson setup builddir \
    --auto-features=disabled \
    -Dgstreamer=enabled \
    -Dtests=disabled \
    -Dman=disabled \
    -Ddocs=disabled \
    -Dexamples=disabled \
    -Dinstalled_tests=disabled \
    2>&1
echo "  Configuration complete."

# --- 4. Build the GStreamer plugin ---
echo ""
echo "Step 4: Building GStreamer plugin..."
# Build only the GST plugin target to save time
ninja -C builddir src/gst/libgstpipewire.so 2>&1 || {
    echo "  Targeted build failed — falling back to full build..."
    ninja -C builddir 2>&1
}

# Locate the built plugin
BUILT_PLUGIN=$(find builddir -name "libgstpipewire.so" -type f | head -1)
if [ -z "${BUILT_PLUGIN}" ]; then
    echo "ERROR: Could not find built libgstpipewire.so"
    exit 1
fi

BUILT_VERSION=$(strings "${BUILT_PLUGIN}" | grep -oP '^\d+\.\d+\.\d+$' | head -1 || echo "${PIPEWIRE_TAG}")
echo "  Built plugin: ${BUILT_PLUGIN}"
echo "  Plugin version: ${BUILT_VERSION}"

# --- 5. Backup stock plugin and install ---
echo ""
echo "Step 5: Installing plugin..."
DEST="${GST_PLUGIN_DIR}/libgstpipewire.so"

if [ -f "${DEST}" ] && [ ! -f "${DEST}.bak-${STOCK_VERSION}" ]; then
    cp "${DEST}" "${DEST}.bak-${STOCK_VERSION}"
    echo "  Backed up stock plugin → ${DEST}.bak-${STOCK_VERSION}"
fi

cp "${BUILT_PLUGIN}" "${DEST}"
chmod 644 "${DEST}"
echo "  Installed ${DEST}"

# --- 6. Verify ---
echo ""
echo "Step 6: Verifying..."
# Clear GStreamer registry cache so it picks up the new plugin
rm -f /root/.cache/gstreamer-1.0/registry.*.bin 2>/dev/null
rm -f /home/fpp/.cache/gstreamer-1.0/registry.*.bin 2>/dev/null

INSTALLED_VERSION=$(PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp \
    gst-inspect-1.0 pipewiresink 2>/dev/null | grep "Version" | awk '{print $NF}' || echo "unknown")
echo "  gst-inspect-1.0 pipewiresink reports: Version ${INSTALLED_VERSION}"

# Check for mode=provide support
if PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp \
    gst-inspect-1.0 pipewiresink 2>/dev/null | grep -q "provide"; then
    echo "  mode=provide: SUPPORTED"
else
    echo "  WARNING: mode=provide not found in plugin properties"
fi

# --- 7. Clean up ---
echo ""
echo "Step 7: Cleaning up build directory..."
rm -rf "${BUILD_DIR}"
echo "  Done."

echo ""
echo "============================================"
echo "PipeWire GStreamer plugin build complete."
echo "  Installed version: ${INSTALLED_VERSION}"
echo "  Stock backup:      ${DEST}.bak-${STOCK_VERSION}"
echo ""
echo "To restore the stock plugin:"
echo "  sudo cp ${DEST}.bak-${STOCK_VERSION} ${DEST}"
echo "============================================"
