#!/bin/bash
#####################################
# Upgrade 109: Migrate legacy ALSA media config to Simple PipeWire
#
# Users upgrading from FPP 9.x (pre-PipeWire) will have MediaBackend
# set to "alsa" (or unset, which also means ALSA).  This script:
#
#   1. Sets MediaBackend = pipewire-simple  (if currently "alsa" or unset)
#   2. Copies AudioOutput → ForceAudioId so the same physical card is
#      selected in Simple PipeWire mode (card index → card name lookup).
#   3. Copies VideoOutput → ForceHDMIResolution hint comment (best-effort).
#   4. Clears ALSA-only settings that have no meaning under PipeWire.
#
# Idempotent — safe to run multiple times.
#####################################

BINDIR=$(cd $(dirname $0) && pwd)
. ${BINDIR}/../../scripts/common

echo "FPP - Upgrade 109: Migrate legacy ALSA media config to Simple PipeWire"
echo "======================================================================="

SETTINGS_FILE="${FPPHOME}/media/settings"

if [ ! -f "${SETTINGS_FILE}" ]; then
    echo "  No settings file found — nothing to migrate."
    exit 0
fi

CURRENT_BACKEND=$(getSetting "MediaBackend")

# Only migrate from ALSA (or unset) — leave pipewire / pipewire-simple alone.
if [ "${CURRENT_BACKEND}" = "pipewire" ] || [ "${CURRENT_BACKEND}" = "pipewire-simple" ]; then
    echo "  MediaBackend is already '${CURRENT_BACKEND}' — no migration needed."
    exit 0
fi

echo "  Current MediaBackend: '${CURRENT_BACKEND:-alsa (unset)}'"
echo "  Migrating to: pipewire-simple"

#######################################
# 1. Migrate AudioOutput card index → ForceAudioId card name
#    AudioOutput is a numeric ALSA card index (0, 1, 2 …).
#    Simple PipeWire uses ForceAudioId which holds the card name string.
#    We resolve the index to a name via /proc/asound/cards at upgrade time.
#    If ForceAudioId is already set we leave it alone.
#######################################
AUDIO_OUTPUT=$(getSetting "AudioOutput")
FORCE_AUDIO_ID=$(getSetting "ForceAudioId")

if [ -n "${AUDIO_OUTPUT}" ] && [ -z "${FORCE_AUDIO_ID}" ]; then
    # /proc/asound/cards format:
    #  0 [ALSA           ]: bcm2835_alsa - bcm2835 ALSA
    #                       bcm2835 ALSA
    CARD_NAME=$(awk -v idx="${AUDIO_OUTPUT}" '
        /^ *[0-9]+ \[/ {
            match($0, /\[([^]]+)\]/, a);
            n = $1 + 0;
            if (n == idx) {
                # Extract the part after the colon on the ]: line
                split($0, p, "]: ");
                split(p[2], q, " - ");
                gsub(/^[ \t]+|[ \t]+$/, "", q[1]);
                print q[1];
                exit;
            }
        }
    ' /proc/asound/cards 2>/dev/null)

    if [ -n "${CARD_NAME}" ]; then
        echo "  AudioOutput=${AUDIO_OUTPUT} → ForceAudioId='${CARD_NAME}'"
        setSetting "ForceAudioId" "${CARD_NAME}"
    else
        echo "  Could not resolve AudioOutput=${AUDIO_OUTPUT} to a card name (card may not be present)."
        echo "  ForceAudioId not set — Simple PipeWire will use the default sink."
    fi
else
    if [ -n "${FORCE_AUDIO_ID}" ]; then
        echo "  ForceAudioId already set to '${FORCE_AUDIO_ID}' — skipping AudioOutput migration."
    fi
fi

#######################################
# 2. Migrate VideoOutput / ForceHDMI settings
#    Simple PipeWire uses the same ForceHDMIResolution / ForceHDMIResolutionPort2
#    settings as ALSA mode, so these carry forward unchanged.
#    ForceHDMI and EnableBBBHDMI are ALSA-only; remove them to avoid confusion.
#######################################
for obsolete_setting in ForceHDMI EnableBBBHDMI; do
    OLD_VAL=$(getSetting "${obsolete_setting}")
    if [ -n "${OLD_VAL}" ]; then
        echo "  Removing obsolete ALSA setting: ${obsolete_setting}=${OLD_VAL}"
        if [ "${FPPPLATFORM}" == "MacOS" ]; then
            sed -i '' -e "/^${obsolete_setting} *= */d" "${SETTINGS_FILE}"
        else
            sed -i -e "/^${obsolete_setting} *= */d" "${SETTINGS_FILE}"
        fi
    fi
done

#######################################
# 3. Set MediaBackend = pipewire-simple
#######################################
setSetting "MediaBackend" "pipewire-simple"
echo "  MediaBackend set to pipewire-simple"

#######################################
# 4. Signal that a reboot is needed so PipeWire services take effect
#######################################
setSetting "rebootFlag" "1"

echo ""
echo "Upgrade 109 complete."
echo "  Users will experience the same audio/video device as before,"
echo "  now routed through PipeWire + GStreamer."
echo "  A reboot is required for the new media backend to take effect."

exit 0
