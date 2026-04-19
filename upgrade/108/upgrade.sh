#!/bin/bash
#####################################
# Upgrade 108: Rename AudioBackend setting to MediaBackend
#
# The setting was renamed to reflect that it controls both audio
# and video backend selection, not just audio.
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/../../scripts/common

echo "FPP - Upgrade 108: Rename AudioBackend → MediaBackend"

SETTINGS_FILE="${FPPHOME}/media/settings"

if [ -f "${SETTINGS_FILE}" ]; then
    if grep -q '^AudioBackend ' "${SETTINGS_FILE}"; then
        sed -i 's/^AudioBackend /MediaBackend /' "${SETTINGS_FILE}"
        echo "  Renamed AudioBackend to MediaBackend in settings"
    else
        echo "  AudioBackend not found in settings (already migrated or not set)"
    fi
fi

exit 0
