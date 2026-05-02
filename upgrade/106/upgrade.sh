#!/bin/bash
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/../../scripts/common

# fppoled now uses nl80211 (via libnl-genl-3) instead of the deprecated
# wireless extensions ioctls, which the kernel warns about and which won't
# be supported by Wi-Fi 7 drivers. Install the libnl dev packages so the
# build picks up the headers.
if [ "${FPPPLATFORM}" != "MacOS" ]; then
    NEEDED=""
    if ! dpkg -l | grep -q "^ii  libnl-3-dev "; then
        NEEDED="${NEEDED} libnl-3-dev"
    fi
    if ! dpkg -l | grep -q "^ii  libnl-genl-3-dev "; then
        NEEDED="${NEEDED} libnl-genl-3-dev"
    fi
    if [ -n "${NEEDED}" ]; then
        echo "FPP - Installing nl80211 build deps:${NEEDED}"
        apt-get update
        apt-get install -y ${NEEDED}
    fi
fi
