#!/usr/bin/bash
#
# Scan for available wireless networks on each wifi device.
#
# Uses nl80211 via `iw` rather than the deprecated wireless extensions
# (iwlist / ifconfig). WEXT triggers a kernel warning on modern kernels
# and stops working entirely on Wi-Fi 7 hardware.

iw dev | awk '$1=="Interface"{print $2}' > /tmp/wifi_devices

while read -r wifi_device || [[ -n $wifi_device ]]; do
    [ -z "$wifi_device" ] && continue

    UPORDOWN=$(cat "/sys/class/net/$wifi_device/operstate" 2>/dev/null || echo down)
    ip link set "$wifi_device" up 2>/dev/null

    if ! iw dev "$wifi_device" scan > /tmp/wifiscan 2>/dev/null; then
        printf "\nWIFI scanning failed.\n\n"
        [ "$UPORDOWN" = "down" ] && ip link set "$wifi_device" down 2>/dev/null
        continue
    fi

    [ "$UPORDOWN" = "down" ] && ip link set "$wifi_device" down 2>/dev/null

    : > /tmp/ssids

    # Parse `iw scan` output into the same per-network line shape the old
    # iwlist-based script produced. Each network starts with a "BSS <mac>"
    # line; "-- associated" on that line flags the currently-connected AP.
    # Signal is dBm; convert to a rough percent via 2*(dBm+100) clamped 0-100.
    awk '
        function emit() {
            if (bss == "") return
            if (signal_dbm == "") pct = 0
            else {
                pct = int(2 * (signal_dbm + 100))
                if (pct < 0) pct = 0
                if (pct > 100) pct = 100
            }
            enc = secure ? "(secure)" : "(open)  "
            con = associated ? " - Connected" : ""
            freq_disp = (freq != "") ? sprintf("%.3f GHz", freq/1000) : ""
            printf("%s  %s %s %s (Signal strength: %d%%)%s\n",
                   ssid, freq_disp, bss, enc, pct, con)
        }
        /^BSS / {
            emit()
            bss=""; ssid=""; freq=""; signal_dbm=""; secure=0; associated=0
            if (match($0, /[0-9a-fA-F:]{17}/)) {
                bss = toupper(substr($0, RSTART, RLENGTH))
            }
            if ($0 ~ /associated/) associated = 1
        }
        /^\tfreq:/       { freq = $2 }
        /^\tsignal:/     { signal_dbm = $2 + 0 }
        /^\tSSID:/       { sub(/^\tSSID: ?/, ""); ssid = $0 }
        /^\tRSN:/        { secure = 1 }
        /^\tWPA:/        { secure = 1 }
        /capability:.*Privacy/ { secure = 1 }
        END { emit() }
    ' /tmp/wifiscan >> /tmp/ssids

    awk '{printf("%5d : %s\n", NR, $0)}' /tmp/ssids > /tmp/sec_ssids

    printf "Wifi Device: %s\n" "$wifi_device"
    printf "Available WIFI Access Points:\n"
    cat /tmp/sec_ssids
    printf "\n"

    rm -f /tmp/ssids /tmp/sec_ssids /tmp/wifiscan
done < /tmp/wifi_devices

rm -f /tmp/wifi_devices
