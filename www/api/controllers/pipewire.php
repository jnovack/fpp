<?php
/////////////////////////////////////////////////////////////////////////////
// PipeWire Audio Groups & AES67 API Controller
//
// Manages audio output groups — collections of sound cards/channels that
// form combined PipeWire sinks. Each group becomes a combine-stream module
// instance with its own virtual sink node.
//
// Also manages multi-instance AES67 (audio-over-IP) configurations.
// Each AES67 instance becomes a PipeWire rtp-sink or rtp-source node
// that can be used standalone or as a member of an audio group.
//
// Config files:
//   $mediaDirectory/config/pipewire-audio-groups.json
//   $mediaDirectory/config/pipewire-aes67-instances.json
/////////////////////////////////////////////////////////////////////////////

require_once '../commandsocket.php';

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/audio/groups
function GetPipeWireAudioGroups()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";

    if (file_exists($configFile)) {
        $data = json_decode(file_get_contents($configFile), true);
        if ($data === null) {
            $data = array("groups" => array());
        }
    } else {
        $data = array("groups" => array());
    }

    return json($data);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/groups
function SavePipeWireAudioGroups()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";

    $data = file_get_contents('php://input');
    $decoded = json_decode($data, true);

    if ($decoded === null) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Invalid JSON"));
    }

    // Validate structure
    if (!isset($decoded['groups']) || !is_array($decoded['groups'])) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing 'groups' array"));
    }

    // Assign IDs if missing
    $maxId = 0;
    foreach ($decoded['groups'] as &$group) {
        if (isset($group['id']) && $group['id'] > $maxId) {
            $maxId = $group['id'];
        }
    }
    unset($group);
    foreach ($decoded['groups'] as &$group) {
        if (!isset($group['id']) || $group['id'] <= 0) {
            $maxId++;
            $group['id'] = $maxId;
        }
    }
    unset($group);

    $data = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($configFile, $data);

    // Trigger a JSON Configuration Backup
    GenerateBackupViaAPI('PipeWire audio groups were modified.');

    return json(array("status" => "OK", "data" => $decoded));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/groups/apply
// Generates PipeWire config files and restarts PipeWire services
function ApplyPipeWireAudioGroups()
{
    global $settings, $SUDO;

    $configFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";
    if (!file_exists($configFile)) {
        return json(array("status" => "OK", "message" => "No audio groups configured"));
    }

    $data = json_decode(file_get_contents($configFile), true);
    if ($data === null || !isset($data['groups']) || empty($data['groups'])) {
        // Remove any existing combine config
        $confPath = "/etc/pipewire/pipewire.conf.d/97-fpp-audio-groups.conf";
        if (file_exists($confPath)) {
            exec($SUDO . " rm -f " . escapeshellarg($confPath));
        }
        // Remove cached copy too
        $cachedConf = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.conf";
        if (file_exists($cachedConf)) {
            unlink($cachedConf);
        }
        // Restart PipeWire to pick up removal (order matters — pulse depends on pipewire socket)
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
        return json(array("status" => "OK", "message" => "Audio groups cleared, PipeWire restarted"));
    }

    // Generate PipeWire config
    $conf = GeneratePipeWireGroupsConfig($data['groups']);

    // Ensure directory exists
    exec($SUDO . " /bin/mkdir -p /etc/pipewire/pipewire.conf.d");

    // Also regenerate input group config (96-) so it stays in sync
    $igFile = $settings['mediaDirectory'] . "/config/pipewire-input-groups.json";
    if (file_exists($igFile)) {
        $igData = json_decode(file_get_contents($igFile), true);
        if (is_array($igData) && isset($igData['inputGroups']) && !empty($igData['inputGroups'])) {
            $igConf = GeneratePipeWireInputGroupsConfig($igData['inputGroups'], $data['groups']);
            $igConfPath = "/etc/pipewire/pipewire.conf.d/96-fpp-input-groups.conf";
            $igTmpFile = tempnam(sys_get_temp_dir(), 'fpp_pw_ig_');
            file_put_contents($igTmpFile, $igConf);
            exec($SUDO . " cp " . escapeshellarg($igTmpFile) . " " . escapeshellarg($igConfPath));
            exec($SUDO . " chmod 644 " . escapeshellarg($igConfPath));
            unlink($igTmpFile);
            file_put_contents($settings['mediaDirectory'] . "/config/pipewire-input-groups.conf", $igConf);
        }
    }

    // Write via temp file + sudo cp (directory is root-owned)
    $confPath = "/etc/pipewire/pipewire.conf.d/97-fpp-audio-groups.conf";
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_pw_');
    file_put_contents($tmpFile, $conf);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($confPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($confPath));
    unlink($tmpFile);

    // Cache a copy in the media config directory so FPPINIT can restore it at boot
    $cachedConf = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.conf";
    file_put_contents($cachedConf, $conf);

    // Install WirePlumber hook to prevent rogue default-target fallback links.
    // Without this, combine-stream output nodes can get linked to the default
    // sink (e.g. Sound Blaster) in addition to their intended filter-chain
    // targets, causing doubled audio.
    InstallWirePlumberFppLinkingHook($SUDO);

    // Stop fppd playback before restarting PipeWire to avoid race conditions
    // where WirePlumber creates rogue links to orphaned streams during the
    // service restart window.
    $wasPlaying = false;
    $resumePlaylist = '';
    $resumeRepeat = false;
    $statusJson = @file_get_contents('http://localhost:32322/fppd/status');
    if ($statusJson !== false) {
        $status = json_decode($statusJson, true);
        if (is_array($status) && isset($status['status']) && $status['status'] == 1) {
            $wasPlaying = true;
            $cp = isset($status['current_playlist']) ? $status['current_playlist'] : array();
            $resumePlaylist = isset($cp['playlist']) ? $cp['playlist'] : '';
            $resumeRepeat = isset($cp['count']) && $cp['count'] === '0';  // 0 = infinite repeat
            // Stop playback immediately
            @file_get_contents('http://localhost:32322/command/Stop%20Now');
            // Wait for fppd to release PipeWire streams
            usleep(500000);
        }
    }

    // Restart PipeWire services to apply (order matters — pulse depends on pipewire socket)
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
    usleep(500000);
    exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
    // Wait for PipeWire core socket to be ready before starting pulse
    for ($i = 0; $i < 10; $i++) {
        if (file_exists('/run/pipewire-fpp/pipewire-0'))
            break;
        usleep(250000);
    }
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");

    // Wait for PulseAudio compat socket to be ready
    for ($i = 0; $i < 10; $i++) {
        if (file_exists('/run/pipewire-fpp/pulse/native'))
            break;
        usleep(250000);
    }

    // Restore ALSA hardware mixer levels to 100% for every member card.
    // WirePlumber auto-detects ALSA devices and may restore saved volume
    // state that zeros the hardware mixer, even though our audio chain uses
    // the custom fpp_card sinks.  Setting the mixer to full here prevents
    // silent outputs after Apply / restart.
    foreach ($data['groups'] as $grp) {
        if (!isset($grp['enabled']) || !$grp['enabled'] || empty($grp['members']))
            continue;
        foreach ($grp['members'] as $mbr) {
            $cId = isset($mbr['cardId']) ? $mbr['cardId'] : '';
            if (empty($cId))
                continue;
            // Resolve ALSA card number and mixer controls from /proc/asound
            $cardLinks = glob('/proc/asound/card[0-9]*');
            foreach ($cardLinks as $cl) {
                $cNum = basename($cl);
                $cNum = preg_replace('/^card/', '', $cNum);
                $idLine = @file_get_contents("/proc/asound/card$cNum/id");
                if ($idLine !== false && trim($idLine) === $cId) {
                    // Set every playback mixer on this card to 100%
                    $mixers = array();
                    exec($SUDO . " amixer -c $cNum scontrols 2>/dev/null | cut -f2 -d\"'\"", $mixers);
                    foreach ($mixers as $mx) {
                        $mx = trim($mx);
                        if (!empty($mx) && stripos($mx, 'Mic') === false && stripos($mx, 'Capture') === false) {
                            exec($SUDO . " amixer -c $cNum sset " . escapeshellarg($mx) . " 100% 2>/dev/null");
                        }
                    }
                    break;
                }
            }
        }
    }

    // Find the first enabled group with members and set it as the default
    // PipeWire sink so FPPD's SDL audio and volume control target it.
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";
    $activeGroup = isset($data['activeGroup']) ? $data['activeGroup'] : '';

    // If no explicit active group, use the first enabled group
    if (empty($activeGroup)) {
        foreach ($data['groups'] as $group) {
            if (isset($group['enabled']) && $group['enabled'] && !empty($group['members'])) {
                $activeGroup = "fpp_group_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($group['name']));
                break;
            }
        }
    }

    if (!empty($activeGroup)) {
        // Set as PipeWire default sink
        exec($SUDO . " " . $env . " pactl set-default-sink " . escapeshellarg($activeGroup) . " 2>&1");

        // Set PipeWireSinkName so volume control targets the correct sink.
        // ForceAudioId is owned by ALSA mode — do not touch it here;
        // SDL routing in PipeWire mode is handled by pactl set-default-sink above.
        WriteSettingToFile('PipeWireSinkName', $activeGroup);
        SendCommand('setSetting,PipeWireSinkName,' . $activeGroup);
    }

    // Resume playback if it was active before the restart
    if ($wasPlaying && !empty($resumePlaylist)) {
        // Small delay to ensure PipeWire pipeline is fully linked
        usleep(500000);
        $repeat = $resumeRepeat ? 'true' : 'false';
        @file_get_contents('http://localhost:32322/command/Start%20Playlist/'
            . rawurlencode($resumePlaylist) . '/' . $repeat);
    }

    return json(array(
        "status" => "OK",
        "message" => "Audio groups applied, PipeWire restarted"
            . ($wasPlaying ? ", playback resumed" : ""),
        "activeGroup" => $activeGroup,
        "restartRequired" => true
    ));
}

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/audio/sinks
// Returns available PipeWire sinks (for volume control targets, etc.)
function GetPipeWireSinks()
{
    global $SUDO;

    $sinks = array();
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";

    exec($SUDO . " " . $env . " pactl list sinks short 2>/dev/null", $output, $return_val);
    if (!$return_val && !empty($output)) {
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2) {
                $sinks[] = array(
                    "index" => $parts[0],
                    "name" => $parts[1],
                    "driver" => isset($parts[2]) ? $parts[2] : "",
                    "format" => isset($parts[3]) ? $parts[3] : "",
                    "state" => isset($parts[4]) ? $parts[4] : ""
                );
            }
        }
    }

    return json($sinks);
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Resolve an ALSA card ID (e.g. "S3", "vc4hdmi0") to its current
// card number by reading /proc/asound/<cardId> symlink.
// Returns the card number as int, or -1 if not found.
function ResolveCardIdToNumber($cardId)
{
    $symlink = "/proc/asound/" . $cardId;
    if (is_link($symlink)) {
        $target = readlink($symlink);
        // target is like "card0", "card1", etc.
        if ($target !== false && preg_match('/^card(\d+)$/', $target, $m)) {
            return intval($m[1]);
        }
    }
    // Fallback: scan /proc/asound/cards
    $cardsFile = @file_get_contents('/proc/asound/cards');
    if ($cardsFile) {
        // Format: " 0 [S3             ]: USB-Audio - ..."
        if (preg_match('/^\s*(\d+)\s*\[' . preg_quote($cardId, '/') . '\s/m', $cardsFile, $m)) {
            return intval($m[1]);
        }
    }
    return -1;
}

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/audio/cards
// Returns available ALSA cards with channel info for group membership UI.
// Uses stable ALSA card IDs (from /proc/asound/) as primary identifiers
// instead of card numbers which can change between reboots.
function GetPipeWireAudioCards()
{
    global $SUDO, $settings;

    $cards = array();

    // Query running PipeWire sinks to map to actual node names
    $pwSinkNames = array(); // substring -> full node name
    $pwEnv = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";
    exec($SUDO . " " . $pwEnv . " pactl list sinks short 2>/dev/null", $sinkLines);
    if (!empty($sinkLines)) {
        foreach ($sinkLines as $sl) {
            $sp = preg_split('/\s+/', trim($sl));
            if (count($sp) >= 2) {
                $sName = $sp[1];
                // Index by identifiable substrings: e.g. alsa_output.usb-Creative_...
                // Extract the middle portion after "alsa_output."
                if (preg_match('/^alsa_output\.(.+?)\.[^.]+$/', $sName, $sm)) {
                    $pwSinkNames[$sm[1]] = $sName;
                }
                // Also index by full name for fpp_card patterns
                if (preg_match('/alsa_output\.(fpp_card\d+)/', $sName, $sm)) {
                    $pwSinkNames[$sm[1]] = $sName;
                }
            }
        }
    }
    unset($sinkLines);

    // Build a map of PipeWire card identifier -> best output profile name
    // This lets us derive expected sink names for cards without active sinks
    // Card name: alsa_card.{identifier}  ->  Sink name: alsa_output.{identifier}.{profile}
    $pwCardProfiles = array(); // identifier -> profile name (e.g. "hdmi-stereo", "analog-stereo")
    $cardOutput = array();
    exec($SUDO . " " . $pwEnv . " pactl list cards 2>/dev/null", $cardOutput);
    $currentCardId = '';
    $currentProfiles = array();
    $currentCardBusPath = '';
    foreach ($cardOutput as $cline) {
        if (preg_match('/^\s+Name:\s+alsa_card\.(.+)/', $cline, $cm)) {
            // Save previous card's profiles
            if ($currentCardId !== '' && !empty($currentProfiles)) {
                $pwCardProfiles[$currentCardId] = $currentProfiles;
            }
            $currentCardId = trim($cm[1]);
            $currentProfiles = array();
            $currentCardBusPath = '';
        }
        // Track bus path for type detection
        if (preg_match('/device\.bus_path\s*=\s*"(.+)"/', $cline, $bm)) {
            $currentCardBusPath = trim($bm[1]);
        }
        // Collect output profiles: "output:hdmi-stereo: Digital Stereo (HDMI) Output (sinks: 1, ...)"
        if (preg_match('/^\s+output:([^\s:]+).*\(sinks:\s*(\d+)/', $cline, $pm)) {
            if (intval($pm[2]) > 0) {
                $currentProfiles[] = $pm[1];
            }
        }
        // If card has pro-audio but no output: profiles (e.g. disconnected HDMI),
        // infer a profile from the bus path / card identifier
        if (preg_match('/^\s+Active Profile:\s+(.+)/', $cline, $am)) {
            if (empty($currentProfiles) && !empty($currentCardId)) {
                // HDMI cards: bus path contains ".hdmi" -> would be hdmi-stereo when connected
                if (preg_match('/\.hdmi$/', $currentCardId) || preg_match('/\.hdmi$/', $currentCardBusPath)) {
                    $currentProfiles[] = 'hdmi-stereo';
                }
                // USB/analog cards would typically be analog-stereo
                elseif (preg_match('/^usb-/', $currentCardId)) {
                    $currentProfiles[] = 'analog-stereo';
                }
            }
        }
    }
    if ($currentCardId !== '' && !empty($currentProfiles)) {
        $pwCardProfiles[$currentCardId] = $currentProfiles;
    }
    unset($cardOutput);

    exec($SUDO . " aplay -l 2>/dev/null | grep '^card'", $output, $return_val);

    // Build by-path and by-id lookup tables for stable hardware identifiers
    $byPathMap = array();  // controlCN -> path string
    $byIdMap = array();    // controlCN -> id string
    exec("ls -la /dev/snd/by-path/ 2>/dev/null", $pathOutput);
    if (!empty($pathOutput)) {
        foreach ($pathOutput as $pline) {
            // lrwxrwxrwx 1 root root 12 Jan  9 15:08 platform-xhci-hcd.1-usb-0:1:1.0 -> ../controlC0
            if (preg_match('/([^\s]+)\s+->\s+\.\.\/controlC(\d+)/', $pline, $pm)) {
                $byPathMap['controlC' . $pm[2]] = $pm[1];
            }
        }
    }
    unset($pathOutput);
    exec("ls -la /dev/snd/by-id/ 2>/dev/null", $idOutput);
    if (!empty($idOutput)) {
        foreach ($idOutput as $iline) {
            if (preg_match('/([^\s]+)\s+->\s+\.\.\/controlC(\d+)/', $iline, $im)) {
                $byIdMap['controlC' . $im[2]] = $im[1];
            }
        }
    }
    unset($idOutput);

    // Build direct alsa-card-number → PipeWire-node-name map via pw-dump.
    // This is more reliable than by-id/by-path heuristics for identical USB
    // cards where Linux only assigns one by-id symlink (e.g. two ICUSBAUDIO7D
    // get one by-id entry pointing to one of them, leaving the other unresolvable).
    $pwSinkByAlsaCardNum = array(); // alsa card number (int) => PW sink node name
    $pwDumpOutput = shell_exec($SUDO . ' ' . $pwEnv . ' pw-dump 2>/dev/null');
    if ($pwDumpOutput) {
        $pwObjects = json_decode($pwDumpOutput, true);
        if (is_array($pwObjects)) {
            foreach ($pwObjects as $pwObj) {
                $pwProps = isset($pwObj['info']['props']) ? $pwObj['info']['props'] : null;
                if (!$pwProps)
                    continue;
                $pwClass = isset($pwProps['media.class']) ? $pwProps['media.class'] : '';
                if ($pwClass !== 'Audio/Sink')
                    continue;
                $pwName = isset($pwProps['node.name']) ? $pwProps['node.name'] : '';
                if ($pwName === '')
                    continue;
                // Strategy 1: WirePlumber-created sinks have alsa.card property
                $pwAlsaCard = isset($pwProps['alsa.card']) ? strval($pwProps['alsa.card']) : '';
                if ($pwAlsaCard !== '') {
                    $pwCardNumInt = intval($pwAlsaCard);
                    // Prefer non-fpp_fx sinks (raw card sinks over filter-chain nodes)
                    if (
                        !isset($pwSinkByAlsaCardNum[$pwCardNumInt]) ||
                        strpos($pwName, 'fpp_fx') === false
                    ) {
                        $pwSinkByAlsaCardNum[$pwCardNumInt] = $pwName;
                    }
                }
                // Strategy 2: FPP-created sinks are named alsa_output.fpp_card{N}
                // and do not have alsa.card set — derive card number from the name.
                if (preg_match('/^alsa_output\.fpp_card(\d+)$/', $pwName, $fppMatch)) {
                    $pwCardNumInt = intval($fppMatch[1]);
                    // FPP-created sinks take priority (they are the managed sink for the card)
                    $pwSinkByAlsaCardNum[$pwCardNumInt] = $pwName;
                }
            }
        }
    }

    if (!$return_val && !empty($output)) {
        $seenCards = array();
        foreach ($output as $line) {
            // Parse: card 0: S3 [Sound Blaster Play! 3], device 0: USB Audio [USB Audio]
            if (preg_match('/^card (\d+):\s*(.+?)\s*\[([^\]]+)\],\s*device\s*(\d+):\s*(.+?)\s*\[([^\]]+)\]/', $line, $matches)) {
                $cardNum = $matches[1];
                $cardId = $matches[2];
                $cardName = $matches[3];
                $deviceNum = $matches[4];
                $deviceId = $matches[5];
                $deviceName = $matches[6];

                if (!isset($seenCards[$cardId])) {
                    $seenCards[$cardId] = true;

                    // Get channel info for this card
                    $channels = 2; // Default stereo
                    $channelInfo = array();
                    exec($SUDO . " amixer -c $cardNum scontrols 2>/dev/null | cut -f2 -d\"'\"", $mixerOutput, $mixerRet);
                    if (!$mixerRet && !empty($mixerOutput)) {
                        foreach ($mixerOutput as $mixer) {
                            $channelInfo[] = trim($mixer);
                        }
                    }
                    unset($mixerOutput);

                    // Try to detect channel count from hw params
                    exec("cat /proc/asound/card$cardNum/pcm0p/sub0/hw_params 2>/dev/null", $hwOutput, $hwRet);
                    if (!$hwRet && !empty($hwOutput)) {
                        foreach ($hwOutput as $hwLine) {
                            if (preg_match('/^channels:\s*(\d+)/', trim($hwLine), $chMatch)) {
                                $channels = intval($chMatch[1]);
                            }
                        }
                    }
                    unset($hwOutput);

                    // Also check codec info for max channels
                    exec("cat /proc/asound/card$cardNum/codec* 2>/dev/null | grep -i 'max channels' | head -1", $codecOutput);
                    if (!empty($codecOutput)) {
                        if (preg_match('/(\d+)/', $codecOutput[0], $chMatch)) {
                            $maxCh = intval($chMatch[1]);
                            if ($maxCh > $channels) {
                                $channels = $maxCh;
                            }
                        }
                    }
                    unset($codecOutput);

                    // Stable identifiers
                    $controlKey = 'controlC' . $cardNum;
                    $byPath = isset($byPathMap[$controlKey]) ? $byPathMap[$controlKey] : '';
                    $byId = isset($byIdMap[$controlKey]) ? $byIdMap[$controlKey] : '';

                    // Resolve actual PipeWire sink node name
                    $pwNodeName = '';
                    // Determine the PipeWire card identifier for this ALSA card
                    $pwCardIdentifier = '';

                    // PRIMARY: pw-dump alsa.card → node.name mapping.
                    // Most reliable for identical USB cards where by-id symlinks
                    // may only exist for one of the two devices.
                    if (isset($pwSinkByAlsaCardNum[intval($cardNum)])) {
                        $pwNodeName = $pwSinkByAlsaCardNum[intval($cardNum)];
                    }

                    // FALLBACK: by-id / by-path heuristics (for cards not resolved above)
                    if (empty($pwNodeName)) {
                        if ($byId && isset($pwSinkNames[$byId])) {
                            $pwNodeName = $pwSinkNames[$byId];
                            $pwCardIdentifier = $byId;
                        } elseif ($byPath) {
                            // Strip trailing -audio if present for matching
                            $byPathBase = preg_replace('/-audio$/', '', $byPath);
                            if (isset($pwSinkNames[$byPathBase])) {
                                $pwNodeName = $pwSinkNames[$byPathBase];
                                $pwCardIdentifier = $byPathBase;
                            } elseif (isset($pwSinkNames[$byPath])) {
                                $pwNodeName = $pwSinkNames[$byPath];
                                $pwCardIdentifier = $byPath;
                            } else {
                                $pwCardIdentifier = $byPathBase;
                            }
                        }
                        // Fallback: try fpp_card pattern
                        if (empty($pwNodeName) && isset($pwSinkNames['fpp_card' . $cardNum])) {
                            $pwNodeName = $pwSinkNames['fpp_card' . $cardNum];
                        }
                        // If still no active sink, derive expected sink name from PipeWire card profiles
                        // Card: alsa_card.{id} -> Sink: alsa_output.{id}.{profile}
                        if (empty($pwNodeName) && !empty($pwCardIdentifier) && isset($pwCardProfiles[$pwCardIdentifier])) {
                            $profiles = $pwCardProfiles[$pwCardIdentifier];
                            if (!empty($profiles)) {
                                $pwNodeName = 'alsa_output.' . $pwCardIdentifier . '.' . $profiles[0];
                            }
                        }
                    }

                    $cards[] = array(
                        "cardNum" => intval($cardNum),
                        "cardId" => $cardId,
                        "cardName" => $cardName,
                        "device" => intval($deviceNum),
                        "deviceName" => $deviceName,
                        "channels" => $channels,
                        "mixerControls" => $channelInfo,
                        "alsaPath" => "hw:" . $cardNum,
                        "byPath" => $byPath,
                        "byId" => $byId,
                        "pwNodeName" => $pwNodeName
                    );
                }
            }
        }
    }

    // --- Also include AES67 virtual sinks as selectable cards ---
    $aes67File = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";
    if (file_exists($aes67File)) {
        $aes67Data = json_decode(file_get_contents($aes67File), true);
        if ($aes67Data && isset($aes67Data['instances']) && is_array($aes67Data['instances'])) {
            foreach ($aes67Data['instances'] as $inst) {
                if (!isset($inst['enabled']) || !$inst['enabled'])
                    continue;
                $mode = isset($inst['mode']) ? $inst['mode'] : 'send';
                // Only sinks (send mode) can be used as group members
                if ($mode !== 'send' && $mode !== 'both')
                    continue;

                $nodeSafeName = 'aes67_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($inst['name']));
                $sinkNodeName = $nodeSafeName . '_send';
                $instChannels = isset($inst['channels']) ? intval($inst['channels']) : 2;

                // Try to find actual PipeWire node name from running sinks
                $pwNodeName = '';
                if (!empty($sinkLines)) {
                    // sinkLines may have been unset, so re-query
                }
                // Search the running sinks for this node
                $sinkSearch = array();
                exec($SUDO . " " . $pwEnv . " pactl list sinks short 2>/dev/null | grep " . escapeshellarg($sinkNodeName), $sinkSearch);
                if (!empty($sinkSearch)) {
                    $sp = preg_split('/\s+/', trim($sinkSearch[0]));
                    if (count($sp) >= 2)
                        $pwNodeName = $sp[1];
                }

                $cards[] = array(
                    "cardNum" => -1,
                    "cardId" => 'aes67_' . $inst['id'],
                    "cardName" => $inst['name'] . ' (AES67 Send)',
                    "device" => 0,
                    "deviceName" => "AES67 RTP Sink",
                    "channels" => $instChannels,
                    "mixerControls" => array(),
                    "alsaPath" => "",
                    "byPath" => "",
                    "byId" => "",
                    "pwNodeName" => !empty($pwNodeName) ? $pwNodeName : $sinkNodeName,
                    "isAES67" => true,
                    "aes67InstanceId" => $inst['id'],
                    "multicastIP" => isset($inst['multicastIP']) ? $inst['multicastIP'] : '',
                    "port" => isset($inst['port']) ? $inst['port'] : 5004
                );
            }
        }
    }

    return json($cards);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/primary-output
// Set the primary audio output sink for FPP (PipeWire mode only)
function SetPipeWirePrimaryOutput()
{
    global $SUDO, $settings;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['sinkName'])) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing sinkName"));
    }

    $sinkName = trim($data['sinkName']);
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";

    if (empty($sinkName)) {
        // Clear — revert to system default
        WriteSettingToFile('PipeWireSinkName', '');
        SendCommand('setSetting,PipeWireSinkName,');
        return json(array("status" => "OK", "message" => "Cleared — using system default", "restartRequired" => true));
    }

    // Set as PipeWire default sink
    exec($SUDO . " " . $env . " pactl set-default-sink " . escapeshellarg($sinkName) . " 2>&1");

    // Write PipeWireSinkName only — ForceAudioId is owned by ALSA mode and must not be
    // overwritten here; SDL routing in PipeWire mode is handled by pactl set-default-sink.
    WriteSettingToFile('PipeWireSinkName', $sinkName);
    SendCommand('setSetting,PipeWireSinkName,' . $sinkName);

    return json(array(
        "status" => "OK",
        "sinkName" => $sinkName,
        "restartRequired" => true
    ));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/group/volume
// Set volume for a specific group or member sink
function SetPipeWireGroupVolume()
{
    global $SUDO;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['sink']) || !isset($data['volume'])) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing sink or volume"));
    }

    $sink = escapeshellarg($data['sink']);
    $volume = intval($data['volume']);
    if ($volume < 0)
        $volume = 0;
    if ($volume > 150)
        $volume = 150;

    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";

    exec($SUDO . " " . $env . " pactl set-sink-volume $sink ${volume}% 2>&1", $output, $return_val);

    if ($return_val) {
        return json(array("status" => "ERROR", "message" => "Failed to set volume", "output" => implode("\n", $output)));
    }

    return json(array("status" => "OK"));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/eq/update
// Real-time EQ parameter update via pw-cli set-param
// Adjusts running filter-chain biquad controls without restarting PipeWire
function UpdatePipeWireEQRealtime()
{
    global $SUDO;

    $data = json_decode(file_get_contents('php://input'), true);
    $groupId = isset($data['groupId']) ? intval($data['groupId']) : 0;
    $cardId = isset($data['cardId']) ? $data['cardId'] : '';
    $bands = isset($data['bands']) ? $data['bands'] : array();
    $channels = isset($data['channels']) ? intval($data['channels']) : 2;

    if (empty($cardId) || empty($bands)) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing cardId or bands"));
    }

    $nodeId = FindFXFilterChainNodeId($groupId, $cardId);

    if ($nodeId === null) {
        // Filter-chain not running — needs Apply first
        return json(array("status" => "NOT_RUNNING", "message" => "EQ filter not active — Save & Apply first"));
    }

    // Build named control key-value pairs for pw-cli set-param.
    // Filter-chain exposes params as: "eq_<ch>_<band>:Freq", "eq_<ch>_<band>:Q", "eq_<ch>_<band>:Gain"
    $channelLabels = array("l", "r", "c", "lfe", "rl", "rr", "sl", "sr");
    $numCh = min($channels, count($channelLabels));

    $paramPairs = array();
    for ($ch = 0; $ch < $numCh; $ch++) {
        $chLabel = $channelLabels[$ch];
        foreach ($bands as $bi => $band) {
            $prefix = "eq_{$chLabel}_{$bi}";
            $freq = floatval(isset($band['freq']) ? $band['freq'] : 1000);
            $q = floatval(isset($band['q']) ? $band['q'] : 1.0);
            $gain = floatval(isset($band['gain']) ? $band['gain'] : 0);
            $paramPairs[] = "\"$prefix:Freq\" $freq";
            $paramPairs[] = "\"$prefix:Q\" $q";
            $paramPairs[] = "\"$prefix:Gain\" $gain";
        }
    }

    $paramStr = implode(' ', $paramPairs);
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $cmd = $SUDO . " " . $env . " pw-cli set-param " . intval($nodeId) . " Props '{ params = [ $paramStr ] }' 2>&1";
    exec($cmd, $output, $ret);

    if ($ret) {
        return json(array("status" => "ERROR", "message" => "pw-cli set-param failed", "output" => implode("\n", $output)));
    }

    return json(array("status" => "OK"));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/delay/update
// Real-time delay adjustment via pw-cli set-param
// Adjusts running filter-chain delay controls without restarting PipeWire
function UpdatePipeWireDelayRealtime()
{
    global $SUDO;

    $data = json_decode(file_get_contents('php://input'), true);
    $groupId = isset($data['groupId']) ? intval($data['groupId']) : 0;
    $cardId = isset($data['cardId']) ? $data['cardId'] : '';
    $delayMs = isset($data['delayMs']) ? floatval($data['delayMs']) : 0;
    $channels = isset($data['channels']) ? intval($data['channels']) : 2;

    if (empty($cardId)) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing cardId"));
    }

    $nodeId = FindFXFilterChainNodeId($groupId, $cardId);

    if ($nodeId === null) {
        return json(array("status" => "NOT_RUNNING", "message" => "Filter chain not active — Save & Apply first"));
    }

    $delaySec = max(0, $delayMs / 1000.0);
    $channelLabels = array("l", "r", "c", "lfe", "rl", "rr", "sl", "sr");
    $numCh = min($channels, count($channelLabels));

    $paramPairs = array();
    for ($ch = 0; $ch < $numCh; $ch++) {
        $chLabel = $channelLabels[$ch];
        $paramPairs[] = "\"delay_{$chLabel}:Delay (s)\" $delaySec";
    }

    $paramStr = implode(' ', $paramPairs);
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $cmd = $SUDO . " " . $env . " pw-cli set-param " . intval($nodeId) . " Props '{ params = [ $paramStr ] }' 2>&1";
    exec($cmd, $output, $ret);

    if ($ret) {
        return json(array("status" => "ERROR", "message" => "pw-cli set-param failed", "output" => implode("\n", $output)));
    }

    return json(array("status" => "OK"));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/sync/start
// Start sync calibration mode: generate a click track and play it on loop
function StartSyncCalibration()
{
    global $SUDO, $settings;

    $data = json_decode(file_get_contents('php://input'), true);
    $groupIndex = isset($data['groupIndex']) ? intval($data['groupIndex']) : 0;

    // Generate click track in the music directory (where "Play Media" can find it)
    $clickFile = $settings['mediaDirectory'] . "/music/fpp_sync_click.wav";
    if (!file_exists($clickFile)) {
        // Generate a 60-second WAV with alternating high/low clicks for easier sync matching
        // 1000Hz beep (20ms) + 980ms silence, then 600Hz beep (20ms) + 980ms silence, looped 30x = 60s
        $cmd = "ffmpeg -y -f lavfi -i \"sine=frequency=1000:duration=0.02,apad=pad_dur=0.98\""
            . " -f lavfi -i \"sine=frequency=600:duration=0.02,apad=pad_dur=0.98\""
            . " -filter_complex \"[0][1]concat=n=2:v=0:a=1,aloop=loop=29:size=88200\""
            . " -t 60 " . escapeshellarg($clickFile) . " 2>&1";
        exec($cmd, $genOutput, $genRet);
        if ($genRet !== 0) {
            // Try sox as fallback
            $cmd = "sox -n " . escapeshellarg($clickFile) . " synth 60 sine 1000 pad 0 0.98 repeat 59 2>&1";
            exec($cmd, $genOutput2, $genRet2);
            if ($genRet2 !== 0) {
                return json(array("status" => "ERROR", "message" => "Failed to generate click track (tried ffmpeg and sox)"));
            }
        }
    }

    // Stop any existing calibration playback first
    StopSyncCalibrationInternal();

    // Play the click track on loop via the "Play Media" command API (VLC-based)
    // Loop count 99 = ~99 minutes — more than enough for calibration
    $url = 'http://localhost/api/command/Play%20Media/fpp_sync_click.wav/99';
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5)));
    $result = @file_get_contents($url, false, $ctx);

    return json(array("status" => "OK", "message" => "Sync calibration started — click track playing on loop"));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/sync/stop
// Stop sync calibration playback
function StopSyncCalibration()
{
    StopSyncCalibrationInternal();
    return json(array("status" => "OK", "message" => "Sync calibration stopped"));
}

function StopSyncCalibrationInternal()
{
    // Stop the click track via the "Stop Media" command API
    $url = 'http://localhost/api/command/Stop%20Media/fpp_sync_click.wav';
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5)));
    @file_get_contents($url, false, $ctx);
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Find the PipeWire node ID for a member's filter-chain
// Looks for "fpp_fx_g<groupId>_<cardId>" first, falls back to legacy "fpp_eq_g<groupId>_<cardId>"
function FindFXFilterChainNodeId($groupId, $cardId)
{
    global $SUDO;

    $cardIdNorm = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cardId));
    $fxNodeName = "fpp_fx_g" . intval($groupId) . "_" . $cardIdNorm;
    // Legacy name for backward compatibility
    $eqNodeName = "fpp_eq_g" . intval($groupId) . "_" . $cardIdNorm;

    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $pwOutput = array();
    exec($SUDO . " " . $env . " pw-cli list-objects Node 2>/dev/null", $pwOutput);

    $nodeId = null;
    $currentId = null;
    foreach ($pwOutput as $line) {
        if (preg_match('/^\s+id (\d+),/', $line, $m)) {
            $currentId = $m[1];
        }
        if (preg_match('/node\.name\s*=\s*"(.+?)"/', $line, $m)) {
            if ($m[1] === $fxNodeName || $m[1] === $eqNodeName) {
                $nodeId = $currentId;
                break;
            }
        }
    }

    return $nodeId;
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Install WirePlumber Lua hook that prevents default-target fallback
// for FPP combine-stream and filter-chain output nodes.
// Without this hook, WirePlumber may create rogue links from combine outputs
// to the default ALSA sink (e.g. Sound Blaster), causing doubled audio, and
// may link filter-chain outputs back to the combine sink, creating loops.
function InstallWirePlumberFppLinkingHook($SUDO)
{
    $luaScript = <<<'LUA'
-- FPP: Block default target fallback for combine-stream and filter-chain nodes
--
-- Problem: When WirePlumber cannot find the defined target for a node,
-- find-default-target links it to the default sink. This causes:
--   1. Filter-chain outputs link back to the combine sink, creating a
--      link-group loop that prevents combine-output -> filter-chain links.
--   2. Combine outputs get rogue links to the default ALSA sink (doubled audio).
--
-- Solution: Block the default-target fallback for FPP nodes that have an
-- explicit target set. On rescan (when the target appears), find-defined-target
-- will succeed and create the correct link.

lutils = require ("linking-utils")
log = Log.open_topic ("s-fpp-linking")

SimpleEventHook {
  name = "linking/fpp-block-combine-fallback",
  after = { "linking/find-defined-target", "linking/find-filter-target" },
  before = { "linking/find-default-target", "linking/find-best-target" },
  interests = {
    EventInterest {
      Constraint { "event.type", "=", "select-target" },
    },
  },
  execute = function (event)
    local source, om, si, si_props, si_flags, target =
        lutils:unwrap_select_target_event (event)

    -- If a target was already found, let it proceed normally
    if target then
      return
    end

    local node_name = si_props ["node.name"] or ""
    local has_target = si_props ["node.target"] ~= nil or
                       si_props ["target.object"] ~= nil

    -- Block default fallback for FPP output group combine-stream outputs
    if node_name:match ("^output%.fpp_group_") then
      log:info (si, "... FPP combine output: blocking default fallback for "
          .. node_name .. ", will retry on rescan")
      event:stop_processing ()
      return
    end

    -- Block default fallback for FPP input group combine-stream outputs
    if node_name:match ("^output%.fpp_input_") then
      log:info (si, "... FPP input group output: blocking default fallback for "
          .. node_name .. ", will retry on rescan")
      event:stop_processing ()
      return
    end

    -- Block default fallback for FPP filter-chain outputs with explicit target
    -- (prevents linking back to combine sink when target isn't ready yet)
    if node_name:match ("^fpp_fx_g%d+_.*_out$") and has_target then
      log:info (si, "... FPP filter-chain output: blocking default fallback for "
          .. node_name .. ", target: " .. tostring (si_props ["node.target"])
          .. ", will retry on rescan")
      event:stop_processing ()
      return
    end

    -- Block default fallback for FPP input group loopback nodes with explicit target
    if node_name:match ("^fpp_loopback_ig%d+_") and has_target then
      log:info (si, "... FPP input loopback: blocking default fallback for "
          .. node_name .. ", will retry on rescan")
      event:stop_processing ()
      return
    end

    -- Block default fallback for FPP input-to-output routing loopback nodes
    if node_name:match ("^fpp_route_ig%d+_to_og%d+") and has_target then
      log:info (si, "... FPP route loopback: blocking default fallback for "
          .. node_name .. ", will retry on rescan")
      event:stop_processing ()
      return
    end
  end
}:register ()
LUA;

    $wpConfContent = <<<'WPCONF'
# FPP: Block default target fallback for combine-stream outputs
# Prevents WirePlumber from creating rogue links from combine-stream
# output nodes to the default sink when the defined target isn't found
# as a SiLinkable. The combine-stream module creates correct internal
# links via the node.target property.

wireplumber.components = [
  {
    name = linking/fpp-block-combine-fallback.lua, type = script/lua
    provides = hooks.linking.target.fpp-block-combine-fallback
  }
]

wireplumber.profiles = {
  main = {
    hooks.linking.target.fpp-block-combine-fallback = required
  }
}
WPCONF;

    // Write Lua script to WirePlumber scripts directory
    $luaPath = "/usr/share/wireplumber/scripts/linking/fpp-block-combine-fallback.lua";
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_wp_');
    file_put_contents($tmpFile, $luaScript);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($luaPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($luaPath));
    unlink($tmpFile);

    // Write WirePlumber component config
    $wpConfPath = "/etc/wireplumber/wireplumber.conf.d/60-fpp-block-combine-fallback.conf";
    exec($SUDO . " /bin/mkdir -p /etc/wireplumber/wireplumber.conf.d");
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_wp_');
    file_put_contents($tmpFile, $wpConfContent);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($wpConfPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($wpConfPath));
    unlink($tmpFile);
}

/////////////////////////////////////////////////////////////////////////////
//  INPUT GROUPS (MIX BUSES)
/////////////////////////////////////////////////////////////////////////////

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/audio/input-groups
function GetPipeWireInputGroups()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-input-groups.json";

    if (file_exists($configFile)) {
        $data = json_decode(file_get_contents($configFile), true);
        if ($data === null) {
            $data = array("inputGroups" => array());
        }
    } else {
        $data = array("inputGroups" => array());
    }

    return json($data);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/input-groups
function SavePipeWireInputGroups()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-input-groups.json";

    $data = file_get_contents('php://input');
    $decoded = json_decode($data, true);

    if ($decoded === null) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Invalid JSON"));
    }

    // Validate structure
    if (!isset($decoded['inputGroups']) || !is_array($decoded['inputGroups'])) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing 'inputGroups' array"));
    }

    // Assign IDs if missing
    $maxId = 0;
    foreach ($decoded['inputGroups'] as &$group) {
        if (isset($group['id']) && $group['id'] > $maxId) {
            $maxId = $group['id'];
        }
    }
    unset($group);
    foreach ($decoded['inputGroups'] as &$group) {
        if (!isset($group['id']) || $group['id'] <= 0) {
            $maxId++;
            $group['id'] = $maxId;
        }
    }
    unset($group);

    $data = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($configFile, $data);

    // Trigger a JSON Configuration Backup
    GenerateBackupViaAPI('PipeWire input groups were modified.');

    return json(array("status" => "OK", "data" => $decoded));
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/pipewire/audio/input-groups/apply
// Generates PipeWire input group config and restarts PipeWire services
function ApplyPipeWireInputGroups()
{
    global $settings, $SUDO;

    $configFile = $settings['mediaDirectory'] . "/config/pipewire-input-groups.json";
    $confPath = "/etc/pipewire/pipewire.conf.d/96-fpp-input-groups.conf";
    $cachedConf = $settings['mediaDirectory'] . "/config/pipewire-input-groups.conf";

    if (!file_exists($configFile)) {
        // No input groups — clean up and reapply output groups only
        if (file_exists($confPath)) {
            exec($SUDO . " rm -f " . escapeshellarg($confPath));
        }
        if (file_exists($cachedConf)) {
            unlink($cachedConf);
        }
        // Restart PipeWire
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
        return json(array("status" => "OK", "message" => "Input groups cleared, PipeWire restarted"));
    }

    $data = json_decode(file_get_contents($configFile), true);
    if ($data === null || !isset($data['inputGroups']) || empty($data['inputGroups'])) {
        // Remove any existing config
        if (file_exists($confPath)) {
            exec($SUDO . " rm -f " . escapeshellarg($confPath));
        }
        if (file_exists($cachedConf)) {
            unlink($cachedConf);
        }
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
        usleep(500000);
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
        return json(array("status" => "OK", "message" => "Input groups cleared, PipeWire restarted"));
    }

    // Load output groups config to determine routing
    $outputGroupsFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";
    $outputGroups = array();
    if (file_exists($outputGroupsFile)) {
        $ogData = json_decode(file_get_contents($outputGroupsFile), true);
        if (is_array($ogData) && isset($ogData['groups'])) {
            $outputGroups = $ogData['groups'];
        }
    }

    // Generate PipeWire config
    $conf = GeneratePipeWireInputGroupsConfig($data['inputGroups'], $outputGroups);

    // Ensure directory exists
    exec($SUDO . " /bin/mkdir -p /etc/pipewire/pipewire.conf.d");

    // Write via temp file + sudo cp
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_pw_ig_');
    file_put_contents($tmpFile, $conf);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($confPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($confPath));
    unlink($tmpFile);

    // Cache a copy
    file_put_contents($cachedConf, $conf);

    // Update WirePlumber hook to include input group patterns
    InstallWirePlumberFppLinkingHook($SUDO);

    // Stop fppd playback before restarting PipeWire
    $wasPlaying = false;
    $resumePlaylist = '';
    $resumeRepeat = false;
    $statusJson = @file_get_contents('http://localhost:32322/fppd/status');
    if ($statusJson !== false) {
        $status = json_decode($statusJson, true);
        if (is_array($status) && isset($status['status']) && $status['status'] == 1) {
            $wasPlaying = true;
            $cp = isset($status['current_playlist']) ? $status['current_playlist'] : array();
            $resumePlaylist = isset($cp['playlist']) ? $cp['playlist'] : '';
            $resumeRepeat = isset($cp['count']) && $cp['count'] === '0';
            @file_get_contents('http://localhost:32322/command/Stop%20Now');
            usleep(500000);
        }
    }

    // Restart PipeWire services
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
    usleep(500000);
    exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
    for ($i = 0; $i < 10; $i++) {
        if (file_exists('/run/pipewire-fpp/pipewire-0'))
            break;
        usleep(250000);
    }
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
    for ($i = 0; $i < 10; $i++) {
        if (file_exists('/run/pipewire-fpp/pulse/native'))
            break;
        usleep(250000);
    }

    // Update fppd routing: if fppd_stream_1 is a member of an input group,
    // route fppd's pipewiresink to the input group instead of the output group.
    $fppdTarget = '';
    foreach ($data['inputGroups'] as $ig) {
        if (!isset($ig['enabled']) || !$ig['enabled'])
            continue;
        if (!isset($ig['members']) || empty($ig['members']))
            continue;
        foreach ($ig['members'] as $mbr) {
            if (isset($mbr['type']) && $mbr['type'] === 'fppd_stream') {
                $igNodeName = "fpp_input_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($ig['name']));
                $fppdTarget = $igNodeName;
                break 2;
            }
        }
    }

    if (!empty($fppdTarget)) {
        // fppd should route to the input group
        $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";
        exec($SUDO . " " . $env . " pactl set-default-sink " . escapeshellarg($fppdTarget) . " 2>&1");
        WriteSettingToFile('PipeWireSinkName', $fppdTarget);
        SendCommand('setSetting,PipeWireSinkName,' . $fppdTarget);
    }

    // Resume playback if it was active
    if ($wasPlaying && !empty($resumePlaylist)) {
        usleep(500000);
        $repeat = $resumeRepeat ? 'true' : 'false';
        @file_get_contents('http://localhost:32322/command/Start%20Playlist/'
            . rawurlencode($resumePlaylist) . '/' . $repeat);
    }

    return json(array(
        "status" => "OK",
        "message" => "Input groups applied, PipeWire restarted"
            . ($wasPlaying ? ", playback resumed" : ""),
        "fppdTarget" => $fppdTarget,
        "restartRequired" => true
    ));
}

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/audio/sources
// Returns available PipeWire audio capture sources (ALSA Audio/Source nodes)
function GetPipeWireAudioSources()
{
    global $SUDO;

    $sources = array();
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $raw = shell_exec($SUDO . " " . $env . " pw-dump 2>/dev/null");
    if (empty($raw)) {
        return json($sources);
    }

    $objects = json_decode($raw, true);
    if (!is_array($objects)) {
        return json($sources);
    }

    foreach ($objects as $obj) {
        $type = isset($obj['type']) ? $obj['type'] : '';
        if ($type !== 'PipeWire:Interface:Node')
            continue;

        $props = isset($obj['info']['props']) ? $obj['info']['props'] : array();
        $mc = isset($props['media.class']) ? $props['media.class'] : '';

        // Only include Audio/Source (capture devices)
        if ($mc !== 'Audio/Source')
            continue;

        $name = isset($props['node.name']) ? $props['node.name'] : '';
        $desc = isset($props['node.description']) ? $props['node.description'] : $name;
        $nick = isset($props['node.nick']) ? $props['node.nick'] : '';

        // Skip PipeWire internal monitors and virtual sources
        if (strpos($name, '.monitor') !== false)
            continue;

        // Get card ID from alsa properties
        $cardId = '';
        if (isset($props['alsa.card'])) {
            // Resolve to stable ID via /proc/asound
            $cardNum = intval($props['alsa.card']);
            $idFile = @file_get_contents("/proc/asound/card$cardNum/id");
            if ($idFile !== false) {
                $cardId = trim($idFile);
            }
        }

        $channels = isset($props['audio.channels']) ? intval($props['audio.channels']) : 2;
        $rate = isset($props['audio.rate']) ? intval($props['audio.rate']) : 48000;

        $sources[] = array(
            'nodeId' => $obj['id'],
            'name' => $name,
            'description' => $desc,
            'nick' => $nick,
            'cardId' => $cardId,
            'channels' => $channels,
            'sampleRate' => $rate,
            'mediaClass' => $mc,
            'state' => isset($obj['info']['state']) ? $obj['info']['state'] : '',
        );
    }

    return json($sources);
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Generate PipeWire input group config (combine-stream + loopback)
function GeneratePipeWireInputGroupsConfig($inputGroups, $outputGroups)
{
    $channelPositions = array(
        1 => "[ MONO ]",
        2 => "[ FL FR ]",
        4 => "[ FL FR RL RR ]",
        6 => "[ FL FR FC LFE RL RR ]",
        8 => "[ FL FR FC LFE RL RR SL SR ]"
    );

    $conf = "# Auto-generated by FPP - PipeWire Input Groups (Mix Buses)\n";
    $conf .= "# Do not edit manually - managed via FPP UI\n";
    $conf .= "# Loaded before 97-fpp-audio-groups.conf so input group nodes\n";
    $conf .= "# exist when output groups are created.\n\n";

    $conf .= "context.modules = [\n";

    foreach ($inputGroups as $ig) {
        if (!isset($ig['enabled']) || !$ig['enabled'])
            continue;
        if (!isset($ig['members']) || empty($ig['members']))
            continue;

        $groupId = isset($ig['id']) ? intval($ig['id']) : 0;
        $groupName = isset($ig['name']) ? $ig['name'] : "Input Group";
        $nodeName = "fpp_input_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($groupName));
        $groupChannels = isset($ig['channels']) ? intval($ig['channels']) : 2;
        $groupPos = isset($channelPositions[$groupChannels]) ? $channelPositions[$groupChannels] : "[ FL FR ]";

        // ── Combine-stream sink for this input group (mix bus) ──
        $conf .= "  # Input Group: $groupName\n";
        $conf .= "  { name = libpipewire-module-combine-stream\n";
        $conf .= "    args = {\n";
        $conf .= "      combine.mode = sink\n";
        $conf .= "      node.name = \"$nodeName\"\n";
        $conf .= "      node.description = \"$groupName\"\n";
        $conf .= "      combine.props = {\n";
        $conf .= "        audio.position = $groupPos\n";
        $conf .= "      }\n";
        $conf .= "      stream.props = {\n";
        $conf .= "        stream.dont-remix = true\n";
        $conf .= "      }\n";
        $conf .= "      stream.rules = [\n";
        $conf .= "        { matches = [ { node.name = \"~fpp_loopback_ig{$groupId}_.*\" } ]\n";
        $conf .= "          actions = { create-stream = { } }\n";
        $conf .= "        }\n";

        // Also match fppd streams that target this input group
        foreach ($ig['members'] as $mbr) {
            if (isset($mbr['type']) && $mbr['type'] === 'fppd_stream') {
                $streamId = isset($mbr['sourceId']) ? $mbr['sourceId'] : 'fppd_stream_1';
                $conf .= "        { matches = [ { node.name = \"$streamId\" } ]\n";
                $conf .= "          actions = { create-stream = { } }\n";
                $conf .= "        }\n";
            }
        }

        $conf .= "      ]\n";
        $conf .= "    }\n";
        $conf .= "  }\n";

        // ── Loopback modules for each capture/AES67 member ──
        foreach ($ig['members'] as $mi => $mbr) {
            $mbrType = isset($mbr['type']) ? $mbr['type'] : '';
            $mbrName = isset($mbr['name']) ? $mbr['name'] : "Member $mi";
            $mbrMute = isset($mbr['mute']) && $mbr['mute'];

            if ($mbrMute)
                continue;  // Don't create loopback for muted sources

            if ($mbrType === 'fppd_stream') {
                // fppd streams connect directly via pipewiresink target-object
                // No loopback needed — the combine-stream rule above handles it
                continue;
            }

            $loopbackName = "fpp_loopback_ig{$groupId}_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($mbrName));
            $loopbackDesc = "$mbrName → $groupName";

            // Determine the source node target
            $sourceTarget = '';
            if ($mbrType === 'capture') {
                $cardId = isset($mbr['cardId']) ? $mbr['cardId'] : '';
                if (empty($cardId))
                    continue;
                // Build the expected ALSA capture node name
                // PipeWire names these: alsa_input.usb-... or alsa_input.<card>
                // We'll use the cardId to find it. The node.target will use pw pattern matching.
                $sourceTarget = '~alsa_input.*' . preg_replace('/[^a-zA-Z0-9]/', '.', $cardId) . '.*';
            } elseif ($mbrType === 'aes67_receive') {
                $instanceId = isset($mbr['instanceId']) ? $mbr['instanceId'] : '';
                if (empty($instanceId))
                    continue;
                $sourceTarget = $instanceId;
            } else {
                continue;
            }

            // Per-member volume (0-100 → 0.0-1.0)
            $volume = isset($mbr['volume']) ? floatval($mbr['volume']) / 100.0 : 1.0;

            $conf .= "  # Loopback: $loopbackDesc\n";
            $conf .= "  { name = libpipewire-module-loopback\n";
            $conf .= "    args = {\n";
            $conf .= "      node.name = \"$loopbackName\"\n";
            $conf .= "      node.description = \"$loopbackDesc\"\n";
            $conf .= "      capture.props = {\n";

            // For capture devices, use node.target with a glob/regex pattern
            if ($mbrType === 'capture') {
                $cardId = $mbr['cardId'];
                $conf .= "        node.target = \"$sourceTarget\"\n";
            } else {
                $conf .= "        node.target = \"$sourceTarget\"\n";
            }

            $conf .= "        media.class = Stream/Input/Audio\n";
            $conf .= "        stream.dont-remix = true\n";

            // Channel mapping if specified
            if (isset($mbr['channelMapping']) && !empty($mbr['channelMapping'])) {
                $srcCh = $mbr['channelMapping']['sourceChannels'];
                $conf .= "        audio.position = [ " . implode(" ", $srcCh) . " ]\n";
            }

            $conf .= "      }\n";
            $conf .= "      playback.props = {\n";
            $conf .= "        node.target = \"$nodeName\"\n";
            $conf .= "        media.class = Stream/Output/Audio\n";

            // Channel mapping for the output side
            if (isset($mbr['channelMapping']) && !empty($mbr['channelMapping'])) {
                $grpCh = $mbr['channelMapping']['groupChannels'];
                $conf .= "        audio.position = [ " . implode(" ", $grpCh) . " ]\n";
            }

            $conf .= "      }\n";
            $conf .= "    }\n";
            $conf .= "  }\n";
        }

        // ── Loopback modules to route input group → output groups ──
        $outputs = isset($ig['outputs']) ? $ig['outputs'] : array();
        foreach ($outputs as $outGroupId) {
            // Find the output group by ID to get its node name
            $outNodeName = '';
            foreach ($outputGroups as $og) {
                if (isset($og['id']) && intval($og['id']) === intval($outGroupId)) {
                    $ogName = isset($og['name']) ? $og['name'] : 'Group';
                    $outNodeName = "fpp_group_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($ogName));
                    break;
                }
            }
            if (empty($outNodeName))
                continue;

            $routeName = "fpp_route_ig{$groupId}_to_og{$outGroupId}";
            $routeDesc = "$groupName → " . $ogName;

            $conf .= "  # Route: $routeDesc\n";
            $conf .= "  { name = libpipewire-module-loopback\n";
            $conf .= "    args = {\n";
            $conf .= "      node.name = \"$routeName\"\n";
            $conf .= "      node.description = \"$routeDesc\"\n";
            $conf .= "      capture.props = {\n";
            $conf .= "        node.target = \"$nodeName\"\n";
            $conf .= "        media.class = Stream/Input/Audio\n";
            $conf .= "        stream.dont-remix = true\n";
            $conf .= "      }\n";
            $conf .= "      playback.props = {\n";
            $conf .= "        node.target = \"$outNodeName\"\n";
            $conf .= "        media.class = Stream/Output/Audio\n";
            $conf .= "      }\n";
            $conf .= "    }\n";
            $conf .= "  }\n";
        }
    }

    $conf .= "]\n";

    return $conf;
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Generate PipeWire combine-stream config from groups
function GeneratePipeWireGroupsConfig($groups)
{
    global $SUDO, $settings;

    $channelPositions = array(
        1 => "[ MONO ]",
        2 => "[ FL FR ]",
        4 => "[ FL FR RL RR ]",
        6 => "[ FL FR FC LFE RL RR ]",
        8 => "[ FL FR FC LFE RL RR SL SR ]"
    );

    $channelPositionArrays = array(
        1 => array("MONO"),
        2 => array("FL", "FR"),
        4 => array("FL", "FR", "RL", "RR"),
        6 => array("FL", "FR", "FC", "LFE", "RL", "RR"),
        8 => array("FL", "FR", "FC", "LFE", "RL", "RR", "SL", "SR")
    );

    $conf = "# Auto-generated by FPP - PipeWire Audio Output Groups\n";
    $conf .= "# Do not edit manually - managed via FPP UI\n\n";

    // -----------------------------------------------------------
    // Query existing PipeWire sink node names so we can match the
    // combine-stream rules against nodes that already exist
    // (created by WirePlumber or the 95-fpp-alsa-sink config).
    // -----------------------------------------------------------
    // Use pw-dump (JSON) to get full node properties including
    // alsa.card and api.alsa.path for reliable card→sink mapping.
    // -----------------------------------------------------------
    $existingSinks = array(); // node.name => true
    $sinkCardNumMap = array(); // ALSA card number (int) => node.name
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $pwDumpJson = '';
    exec($SUDO . " " . $env . " pw-dump 2>/dev/null", $pwDumpLines);
    $pwDumpJson = implode("\n", $pwDumpLines);
    unset($pwDumpLines);
    $pwDumpData = json_decode($pwDumpJson, true);
    unset($pwDumpJson);
    if (is_array($pwDumpData)) {
        foreach ($pwDumpData as $obj) {
            if (!isset($obj['type']) || $obj['type'] !== 'PipeWire:Interface:Node')
                continue;
            $props = isset($obj['info']['props']) ? $obj['info']['props'] : array();
            $nodeName = isset($props['node.name']) ? $props['node.name'] : '';
            $mediaClass = isset($props['media.class']) ? $props['media.class'] : '';
            if ($nodeName && $mediaClass === 'Audio/Sink') {
                $existingSinks[$nodeName] = true;
                // Map ALSA card number to this sink node name.
                // WirePlumber-managed sinks have alsa.card set directly.
                if (isset($props['alsa.card'])) {
                    $cn = intval($props['alsa.card']);
                    if (!isset($sinkCardNumMap[$cn])) {
                        $sinkCardNumMap[$cn] = $nodeName;
                    }
                }
                // FPP-created sinks have api.alsa.path (e.g. "hw:0" or "hw:S3")
                // but no alsa.card.  Resolve the path to a card number.
                elseif (isset($props['api.alsa.path'])) {
                    $alsaPath = $props['api.alsa.path'];
                    // Extract device specifier after "hw:"
                    if (preg_match('/^hw:(.+)$/', $alsaPath, $hm)) {
                        $dev = $hm[1];
                        if (ctype_digit($dev)) {
                            $cn = intval($dev);
                        } else {
                            // Stable card ID — resolve via /proc/asound
                            $cn = ResolveCardIdToNumber($dev);
                        }
                        if ($cn >= 0 && !isset($sinkCardNumMap[$cn])) {
                            $sinkCardNumMap[$cn] = $nodeName;
                        }
                    }
                }
            }
        }
    }
    unset($pwDumpData);

    // Resolve card IDs to existing PipeWire node names using the
    // alsa.card / api.alsa.path map built from pw-dump above.
    // If no existing sink is found, we'll skip the card (can't safely
    // create raw ALSA adapters for cards like HDMI that need special formats).
    $cardNodeMap = array();   // cardId -> PipeWire node name
    $unresolvedCards = array();

    foreach ($groups as $group) {
        if (!isset($group['enabled']) || !$group['enabled'])
            continue;
        if (!isset($group['members']) || empty($group['members']))
            continue;
        foreach ($group['members'] as $member) {
            $cardId = isset($member['cardId']) ? $member['cardId'] : '';
            if (empty($cardId) || isset($cardNodeMap[$cardId]))
                continue;

            // AES67 virtual sinks: cardId starts with "aes67_"
            if (strpos($cardId, 'aes67_') === 0) {
                // Look up the instance from the AES67 config to get node name
                $aes67File = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";
                if (file_exists($aes67File)) {
                    $aes67Json = json_decode(file_get_contents($aes67File), true);
                    if ($aes67Json && isset($aes67Json['instances'])) {
                        $aes67InstId = intval(str_replace('aes67_', '', $cardId));
                        foreach ($aes67Json['instances'] as $ai) {
                            if (isset($ai['id']) && intval($ai['id']) === $aes67InstId && isset($ai['enabled']) && $ai['enabled']) {
                                $aesNodeName = 'aes67_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($ai['name'])) . '_send';
                                // Check if running in PipeWire; if so use the running name
                                if (isset($existingSinks[$aesNodeName])) {
                                    $cardNodeMap[$cardId] = $aesNodeName;
                                } else {
                                    // May not be running yet but will be after apply
                                    $cardNodeMap[$cardId] = $aesNodeName;
                                }
                                break;
                            }
                        }
                    }
                }
                if (!isset($cardNodeMap[$cardId])) {
                    $unresolvedCards[] = $cardId . " (AES67 instance not found or disabled)";
                }
                continue;
            }

            $cardNum = ResolveCardIdToNumber($cardId);
            if ($cardNum < 0) {
                $unresolvedCards[] = $cardId;
                continue;
            }

            // Look up PipeWire sink via the alsa.card / api.alsa.path
            // map built from pw-dump above.  This is reliable regardless
            // of how WirePlumber chose to name the node.
            if (isset($sinkCardNumMap[$cardNum])) {
                $cardNodeMap[$cardId] = $sinkCardNumMap[$cardNum];
            } else {
                $unresolvedCards[] = $cardId . " (card $cardNum — no PipeWire sink found)";
            }
        }
    }

    if (!empty($unresolvedCards)) {
        $conf .= "# WARNING: Could not find PipeWire sinks for: " . implode(', ', $unresolvedCards) . "\n";
        $conf .= "# These cards will be skipped from combine groups.\n\n";
    }

    // Create modules array: filter-chain modules first, then combine-stream
    $conf .= "context.modules = [\n";

    // ---------------------------------------------------------------
    // Phase 1: Generate filter-chain modules for members that need
    // EQ processing and/or delay compensation.
    // These must load before combine-stream so their virtual sinks
    // exist when combine-stream scans for matching nodes.
    //
    // Three cases per member:
    //   a) EQ only         → filter-chain with biquad nodes
    //   b) Delay only      → filter-chain with delay nodes
    //   c) EQ + Delay      → single filter-chain: delay → EQ chain
    // ---------------------------------------------------------------
    $filterNodeMap = array();  // "groupId_cardId" -> filter virtual sink node name
    $channelLabels = array("l", "r", "c", "lfe", "rl", "rr", "sl", "sr");

    foreach ($groups as $group) {
        if (!isset($group['enabled']) || !$group['enabled'])
            continue;
        if (!isset($group['members']) || empty($group['members']))
            continue;

        $groupId = isset($group['id']) ? intval($group['id']) : 0;

        foreach ($group['members'] as $member) {
            $cardId = isset($member['cardId']) ? $member['cardId'] : '';
            if (empty($cardId) || !isset($cardNodeMap[$cardId]))
                continue;

            $hasEQ = isset($member['eq']['enabled']) && $member['eq']['enabled']
                && isset($member['eq']['bands']) && !empty($member['eq']['bands']);
            $delayMs = isset($member['delayMs']) ? floatval($member['delayMs']) : 0;
            // Always create delay nodes so real-time adjustment is possible during calibration
            $hasDelay = true;

            $memberChannels = isset($member['channels']) ? intval($member['channels']) : 2;
            $numCh = min($memberChannels, count($channelLabels));
            $positions = isset($channelPositionArrays[$memberChannels]) ? $channelPositionArrays[$memberChannels] : $channelPositionArrays[2];
            $posStr = "[ " . implode(" ", $positions) . " ]";

            $realNodeName = $cardNodeMap[$cardId];
            $cardIdNorm = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cardId));
            // Use "fpp_fx_" prefix for the unified filter-chain node name
            $fxNodeName = "fpp_fx_g" . $groupId . "_" . $cardIdNorm;
            $fxOutName = $fxNodeName . "_out";
            $fxKey = $groupId . "_" . $cardId;

            // Build description
            $cardLabel = isset($member['cardName']) ? $member['cardName'] : $cardId;
            $fxParts = array();
            if ($hasDelay)
                $fxParts[] = "Delay";
            if ($hasEQ)
                $fxParts[] = "EQ";
            $fxDesc = implode("+", $fxParts) . ": " . $cardLabel;

            $conf .= "  # Filter chain (" . implode("+", $fxParts) . ") for: $cardLabel (Group $groupId)\n";
            $conf .= "  { name = libpipewire-module-filter-chain\n";
            $conf .= "    args = {\n";
            $conf .= "      node.description = \"$fxDesc\"\n";
            $conf .= "      filter.graph = {\n";
            $conf .= "        nodes = [\n";

            // --- Delay nodes (one per channel) ---
            if ($hasDelay) {
                $delaySec = $delayMs / 1000.0;
                $maxDelay = max(5.0, $delaySec * 1.5);
                for ($ch = 0; $ch < $numCh; $ch++) {
                    $chLabel = $channelLabels[$ch];
                    $conf .= "          { type = builtin label = delay name = delay_{$chLabel} config = { \"max-delay\" = $maxDelay } control = { \"Delay (s)\" = $delaySec } }\n";
                }
            }

            // --- EQ nodes (one per channel x band) ---
            if ($hasEQ) {
                $bands = $member['eq']['bands'];
                for ($ch = 0; $ch < $numCh; $ch++) {
                    $chLabel = $channelLabels[$ch];
                    foreach ($bands as $bi => $band) {
                        $type = isset($band['type']) ? $band['type'] : 'bq_peaking';
                        $freq = floatval(isset($band['freq']) ? $band['freq'] : 1000);
                        $gain = floatval(isset($band['gain']) ? $band['gain'] : 0);
                        $q = floatval(isset($band['q']) ? $band['q'] : 1.0);
                        $conf .= "          { type = builtin label = $type name = eq_{$chLabel}_{$bi} control = { \"Freq\" = $freq \"Q\" = $q \"Gain\" = $gain } }\n";
                    }
                }
            }

            $conf .= "        ]\n";

            // --- Links: chain delay → EQ in series for each channel ---
            $conf .= "        links = [\n";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];

                if ($hasDelay && $hasEQ) {
                    // Link delay output → first EQ band input
                    $conf .= "          { output = \"delay_{$chLabel}:Out\" input = \"eq_{$chLabel}_0:In\" }\n";
                }

                if ($hasEQ) {
                    $bands = $member['eq']['bands'];
                    // Chain EQ bands in series
                    for ($bi = 1; $bi < count($bands); $bi++) {
                        $prevBi = $bi - 1;
                        $conf .= "          { output = \"eq_{$chLabel}_{$prevBi}:Out\" input = \"eq_{$chLabel}_{$bi}:In\" }\n";
                    }
                }
            }
            $conf .= "        ]\n";

            // --- Inputs: first node of each channel's chain ---
            $conf .= "        inputs = [";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];
                if ($hasDelay) {
                    $conf .= " \"delay_{$chLabel}:In\"";
                } else {
                    $conf .= " \"eq_{$chLabel}_0:In\"";
                }
            }
            $conf .= " ]\n";

            // --- Outputs: last node of each channel's chain ---
            $conf .= "        outputs = [";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];
                if ($hasEQ) {
                    $lastBi = count($member['eq']['bands']) - 1;
                    $conf .= " \"eq_{$chLabel}_{$lastBi}:Out\"";
                } else {
                    $conf .= " \"delay_{$chLabel}:Out\"";
                }
            }
            $conf .= " ]\n";

            $conf .= "      }\n"; // filter.graph

            // Capture props (virtual sink that combine-stream will match)
            $conf .= "      capture.props = {\n";
            $conf .= "        node.name = \"$fxNodeName\"\n";
            $conf .= "        media.class = Audio/Sink\n";
            $conf .= "        audio.channels = $numCh\n";
            $conf .= "        audio.position = $posStr\n";
            $conf .= "      }\n";

            // Playback props (output to real sink)
            $conf .= "      playback.props = {\n";
            $conf .= "        node.name = \"$fxOutName\"\n";
            $conf .= "        node.passive = true\n";
            $conf .= "        node.target = \"$realNodeName\"\n";
            $conf .= "        stream.dont-remix = true\n";
            $conf .= "        audio.channels = $numCh\n";
            $conf .= "        audio.position = $posStr\n";
            $conf .= "      }\n";

            $conf .= "    }\n"; // args
            $conf .= "  }\n";

            $filterNodeMap[$fxKey] = $fxNodeName;
        }
    }

    // Backward compat: $eqNodeMap is now $filterNodeMap
    $eqNodeMap = $filterNodeMap;

    // ---------------------------------------------------------------
    // Phase 2: Generate combine-stream modules for each group
    // ---------------------------------------------------------------
    foreach ($groups as $group) {
        if (!isset($group['enabled']) || !$group['enabled'])
            continue;
        if (!isset($group['members']) || empty($group['members']))
            continue;

        $groupName = isset($group['name']) ? $group['name'] : "Group";
        $nodeName = "fpp_group_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($groupName));
        $groupChannels = isset($group['channels']) ? intval($group['channels']) : 2;
        $groupPos = isset($channelPositions[$groupChannels]) ? $channelPositions[$groupChannels] : "[ FL FR ]";
        $latencyCompensate = (isset($group['latencyCompensate']) && $group['latencyCompensate']) ? "true" : "false";
        $groupId = isset($group['id']) ? intval($group['id']) : 0;

        // Check if this group has at least one resolvable member
        $hasMembers = false;
        foreach ($group['members'] as $member) {
            $cardId = isset($member['cardId']) ? $member['cardId'] : '';
            if (!empty($cardId) && isset($cardNodeMap[$cardId])) {
                $hasMembers = true;
                break;
            }
        }
        if (!$hasMembers)
            continue;

        $conf .= "  { name = libpipewire-module-combine-stream\n";
        $conf .= "    args = {\n";
        $conf .= "      combine.mode = sink\n";
        $conf .= "      node.name = \"$nodeName\"\n";
        $conf .= "      node.description = \"$groupName\"\n";
        $conf .= "      combine.latency-compensate = $latencyCompensate\n";
        $conf .= "      combine.props = {\n";
        $conf .= "        audio.position = $groupPos\n";
        $conf .= "      }\n";
        $conf .= "      stream.props = {\n";
        $conf .= "        stream.dont-remix = true\n";
        $conf .= "      }\n";
        $conf .= "      stream.rules = [\n";

        foreach ($group['members'] as $member) {
            $cardId = isset($member['cardId']) ? $member['cardId'] : '';
            if (empty($cardId) || !isset($cardNodeMap[$cardId]))
                continue;

            // Use EQ virtual sink if filter-chain was generated for this member
            $eqKey = $groupId . "_" . $cardId;
            if (isset($eqNodeMap[$eqKey])) {
                $memberNodeName = $eqNodeMap[$eqKey];
            } else {
                $memberNodeName = $cardNodeMap[$cardId];
            }

            // Channel mapping for this member within the group
            $memberChannels = isset($member['channels']) ? intval($member['channels']) : 2;
            $memberPos = isset($channelPositions[$memberChannels]) ? $channelPositions[$memberChannels] : "[ FL FR ]";

            // If channel mapping is specified, use it
            if (isset($member['channelMapping']) && !empty($member['channelMapping'])) {
                $combinePos = "[ " . implode(" ", $member['channelMapping']['groupChannels']) . " ]";
                $streamPos = "[ " . implode(" ", $member['channelMapping']['cardChannels']) . " ]";
            } else {
                $combinePos = $memberPos;
                $streamPos = $memberPos;
            }

            $conf .= "        { matches = [\n";
            $conf .= "            { media.class = \"Audio/Sink\"\n";
            $conf .= "              node.name = \"$memberNodeName\"\n";
            $conf .= "            }\n";
            $conf .= "          ]\n";
            $conf .= "          actions = {\n";
            $conf .= "            create-stream = {\n";
            $conf .= "              node.target = \"$memberNodeName\"\n";
            $conf .= "              combine.audio.position = $combinePos\n";
            $conf .= "              audio.position = $streamPos\n";
            $conf .= "            }\n";
            $conf .= "          }\n";
            $conf .= "        }\n";
        }

        $conf .= "      ]\n";
        $conf .= "    }\n";
        $conf .= "  }\n";
    }

    $conf .= "]\n";

    return $conf;
}

/////////////////////////////////////////////////////////////////////////////
//  AES67 MULTI-INSTANCE API
/////////////////////////////////////////////////////////////////////////////

// GET /api/pipewire/aes67/instances
function GetAES67Instances()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";
    if (file_exists($configFile)) {
        $data = json_decode(file_get_contents($configFile), true);
        if ($data !== null) {
            return json($data);
        }
    }
    return json(array("instances" => array(), "ptpEnabled" => true, "ptpInterface" => ""));
}

// POST /api/pipewire/aes67/instances
function SaveAES67Instances()
{
    global $settings;
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";

    $data = file_get_contents('php://input');
    $parsed = json_decode($data, true);
    if ($parsed === null) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Invalid JSON"));
    }
    // Validate structure
    if (!isset($parsed['instances']) || !is_array($parsed['instances'])) {
        http_response_code(400);
        return json(array("status" => "ERROR", "message" => "Missing instances array"));
    }
    // Validate each instance
    $nextId = 1;
    foreach ($parsed['instances'] as &$inst) {
        if (!isset($inst['id'])) {
            $inst['id'] = $nextId;
        }
        if ($inst['id'] >= $nextId)
            $nextId = $inst['id'] + 1;
        if (empty($inst['name']))
            $inst['name'] = 'AES67 Instance ' . $inst['id'];
        if (empty($inst['mode']))
            $inst['mode'] = 'send';
        if (empty($inst['multicastIP']))
            $inst['multicastIP'] = '239.69.0.' . $inst['id'];
        if (empty($inst['port']))
            $inst['port'] = 5004;
        if (empty($inst['channels']))
            $inst['channels'] = 2;
        if (empty($inst['sessionName']))
            $inst['sessionName'] = $inst['name'];
        if (!isset($inst['ptime']))
            $inst['ptime'] = 4;
        if (!isset($inst['latency']))
            $inst['latency'] = 10;
        if (!isset($inst['sapEnabled']))
            $inst['sapEnabled'] = true;
        if (!isset($inst['enabled']))
            $inst['enabled'] = true;
    }
    unset($inst);

    file_put_contents($configFile, json_encode($parsed, JSON_PRETTY_PRINT));
    return json(array("status" => "OK"));
}

// POST /api/pipewire/aes67/apply
function ApplyAES67Instances()
{
    global $settings;

    // AES67 is managed by AES67Manager in fppd (GStreamer-based).
    // Signal fppd to reload AES67 config via the command API.
    $configFile = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";

    if (!file_exists($configFile)) {
        // Signal cleanup
        $result = @file_get_contents('http://localhost/api/command', false, stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(array('command' => 'AES67 Cleanup')),
                'timeout' => 5
            )
        )));
        return json(array("status" => "OK", "message" => "No AES67 instances configured"));
    }

    // Signal fppd to apply config
    $result = @file_get_contents('http://localhost/api/command', false, stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode(array('command' => 'AES67 Apply')),
            'timeout' => 10
        )
    )));

    if ($result === false) {
        return json(array(
            "status" => "ERROR",
            "message" => "Failed to signal fppd — is it running?"
        ));
    }

    return json(array(
        "status" => "OK",
        "message" => "AES67 configuration applied via GStreamer"
    ));
}

// GET /api/pipewire/aes67/status
function GetAES67Status()
{
    // Query AES67Manager in fppd for pipeline and PTP status
    $result = @file_get_contents('http://localhost:32322/aes67/status');

    if ($result !== false) {
        $data = json_decode($result, true);
        if ($data !== null) {
            return json($data);
        }
    }

    // Fallback: fppd not running or endpoint not available
    return json(array(
        "pipelines" => array(),
        "ptpSynced" => false,
        "ptpOffsetNs" => 0,
        "discoveredStreams" => array()
    ));
}

// GET /api/pipewire/aes67/interfaces
function GetAES67NetworkInterfaces()
{
    $interfaces = array();
    exec("ip -o link show | awk -F': ' '{print \$2}' | grep -v lo", $output);
    if (!empty($output)) {
        foreach ($output as $iface) {
            $iface = trim($iface);
            if (!empty($iface))
                $interfaces[] = $iface;
        }
    }
    return json($interfaces);
}


// AES67 audio-over-IP is managed by AES67Manager in fppd (GStreamer-based).
// Config JSON: $mediaDirectory/config/pipewire-aes67-instances.json
// Apply: POST /api/command {"command":"AES67 Apply"} → fppd rebuilds GStreamer pipelines
// Status: GET /api/pipewire/aes67/status → queries AES67Manager in fppd
// PTP: GstPtpClock (replaces external ptp4l daemon)
// SAP: Built-in C++ SAP announcer (replaces fpp_aes67_sap Python daemon)

/////////////////////////////////////////////////////////////////////////////
// GET /api/pipewire/graph
// Returns the live PipeWire graph as { nodes, ports, links } for the
// pipeline visualizer page.  Only audio-related nodes are included by
// default; pass ?all=1 to include everything.
function GetPipeWireGraph()
{
    global $SUDO;

    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    $raw = shell_exec($SUDO . " " . $env . " pw-dump 2>/dev/null");
    if (empty($raw)) {
        return json(array('nodes' => array(), 'ports' => array(), 'links' => array()));
    }

    $objects = json_decode($raw, true);
    if (!is_array($objects)) {
        return json(array('nodes' => array(), 'ports' => array(), 'links' => array()));
    }

    $showAll = isset($_GET['all']) && $_GET['all'] == '1';

    // Classify audio-related media classes
    $audioClasses = array(
        'Audio/Sink',
        'Audio/Source',
        'Audio/Duplex',
        'Stream/Output/Audio',
        'Stream/Input/Audio',
        'Video/Source',   // keep for completeness
    );

    // First pass — collect nodes, ports, links
    $nodes = array();
    $ports = array();
    $links = array();
    $audioNodeIds = array();   // set of node IDs that are audio-related

    foreach ($objects as $obj) {
        $type = isset($obj['type']) ? $obj['type'] : '';
        $info = isset($obj['info']) ? $obj['info'] : array();
        $props = isset($info['props']) ? $info['props'] : array();

        if ($type === 'PipeWire:Interface:Node') {
            $mc = isset($props['media.class']) ? $props['media.class'] : '';
            $name = isset($props['node.name']) ? $props['node.name'] : '';
            $desc = isset($props['node.description']) ? $props['node.description'] : $name;
            $nick = isset($props['node.nick']) ? $props['node.nick'] : '';
            $state = isset($info['state']) ? $info['state'] : '';
            $factoryName = isset($props['factory.name']) ? $props['factory.name'] : '';

            // Skip non-audio nodes unless ?all=1
            if (!$showAll) {
                if (empty($mc) || !in_array($mc, $audioClasses)) {
                    // Also keep Midi-Bridge? No — skip it.
                    continue;
                }
            }

            $audioNodeIds[$obj['id']] = true;

            $node = array(
                'id' => $obj['id'],
                'name' => $name,
                'description' => $desc,
                'nick' => $nick,
                'mediaClass' => $mc,
                'state' => $state,
                'factory' => $factoryName,
                'properties' => array(),
            );

            // Pick interesting properties for the detail panel
            $interesting = array(
                'audio.channels',
                'audio.format',
                'audio.rate',
                'api.alsa.card',
                'api.alsa.card.name',
                'api.alsa.pcm.card',
                'api.alsa.headroom',
                'api.alsa.period-size',
                'api.alsa.period-num',
                'node.latency',
                'node.group',
                'node.sync-group',
                'media.name',
                'media.type',
                'stream.is-live',
                'node.always-process',
                'application.name',
                'application.process.binary',
                'object.path',
            );
            foreach ($interesting as $key) {
                if (isset($props[$key])) {
                    $node['properties'][$key] = $props[$key];
                }
            }

            $nodes[] = $node;
        } elseif ($type === 'PipeWire:Interface:Port') {
            $ports[] = array(
                'id' => $obj['id'],
                'nodeId' => isset($props['node.id']) ? (int) $props['node.id'] : 0,
                'name' => isset($props['port.name']) ? $props['port.name'] : '',
                'direction' => isset($info['direction']) ? $info['direction'] : '',
                'channel' => isset($props['audio.channel']) ? $props['audio.channel'] : '',
            );
        } elseif ($type === 'PipeWire:Interface:Link') {
            $links[] = array(
                'id' => $obj['id'],
                'outputNodeId' => isset($info['output-node-id']) ? (int) $info['output-node-id'] : 0,
                'outputPortId' => isset($info['output-port-id']) ? (int) $info['output-port-id'] : 0,
                'inputNodeId' => isset($info['input-node-id']) ? (int) $info['input-node-id'] : 0,
                'inputPortId' => isset($info['input-port-id']) ? (int) $info['input-port-id'] : 0,
                'state' => isset($info['state']) ? $info['state'] : '',
            );
        }
    }

    // Filter ports & links to only include those belonging to audio nodes
    if (!$showAll) {
        $ports = array_values(array_filter($ports, function ($p) use ($audioNodeIds) {
            return isset($audioNodeIds[$p['nodeId']]);
        }));
        $links = array_values(array_filter($links, function ($l) use ($audioNodeIds) {
            return isset($audioNodeIds[$l['outputNodeId']]) || isset($audioNodeIds[$l['inputNodeId']]);
        }));
    }

    // Enrich delay/effect nodes with audio group config (delay, EQ, volume)
    global $settings;
    $groupsFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";
    if (file_exists($groupsFile)) {
        $groupsCfg = json_decode(file_get_contents($groupsFile), true);
        if (is_array($groupsCfg) && isset($groupsCfg['groups'])) {
            // Build lookup: normalised cardId → member config
            $memberLookup = array(); // 'g{groupId}_{cardId}' → member
            $groupLookup = array();  // groupId → group
            foreach ($groupsCfg['groups'] as $group) {
                $gid = $group['id'];
                $groupLookup[$gid] = $group;
                if (isset($group['members'])) {
                    foreach ($group['members'] as $member) {
                        $cid = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $member['cardId']));
                        $key = 'g' . $gid . '_' . $cid;
                        $memberLookup[$key] = $member;
                    }
                }
            }
            // Match delay nodes (fpp_fx_g{N}_{cardId}) to their member config
            foreach ($nodes as &$node) {
                $nm = $node['name'];
                if (preg_match('/^fpp_fx_g(\d+)_(.+?)(_out)?$/', $nm, $m)) {
                    $key = 'g' . $m[1] . '_' . $m[2];
                    if (isset($memberLookup[$key])) {
                        $mem = $memberLookup[$key];
                        if (isset($mem['delayMs'])) {
                            $node['properties']['fpp.delay.ms'] = $mem['delayMs'];
                        }
                        if (isset($mem['eq']['enabled'])) {
                            $node['properties']['fpp.eq.enabled'] = $mem['eq']['enabled'];
                        }
                    }
                }
                // Enrich group nodes with member count
                if (preg_match('/^fpp_group_/', $nm)) {
                    // Find the group by matching the slugified name
                    foreach ($groupsCfg['groups'] as $group) {
                        $slug = 'fpp_group_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $group['name']));
                        if ($nm === $slug && isset($group['members'])) {
                            $node['properties']['fpp.group.members'] = count($group['members']);
                            if (isset($group['latencyCompensate'])) {
                                $node['properties']['fpp.group.latencyCompensate'] = $group['latencyCompensate'];
                            }
                        }
                    }
                }
            }
            unset($node);
        }
    }

    // Enrich input group nodes with config data
    $igFile = $settings['mediaDirectory'] . "/config/pipewire-input-groups.json";
    if (file_exists($igFile)) {
        $igCfg = json_decode(file_get_contents($igFile), true);
        if (is_array($igCfg) && isset($igCfg['inputGroups'])) {
            foreach ($nodes as &$node) {
                $nm = $node['name'];
                // Match input group combine-stream nodes (fpp_input_*)
                if (preg_match('/^fpp_input_/', $nm)) {
                    foreach ($igCfg['inputGroups'] as $ig) {
                        $slug = 'fpp_input_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $ig['name']));
                        if ($nm === $slug) {
                            $node['properties']['fpp.inputGroup'] = true;
                            $node['properties']['fpp.inputGroup.members'] = isset($ig['members']) ? count($ig['members']) : 0;
                            $node['properties']['fpp.inputGroup.outputs'] = isset($ig['outputs']) ? count($ig['outputs']) : 0;
                            break;
                        }
                    }
                }
                // Match loopback nodes (fpp_loopback_ig*)
                if (preg_match('/^fpp_loopback_ig(\d+)_/', $nm, $m)) {
                    $node['properties']['fpp.inputGroup.loopback'] = true;
                    $node['properties']['fpp.inputGroup.id'] = intval($m[1]);
                }
                // Match route nodes (fpp_route_ig*_to_og*)
                if (preg_match('/^fpp_route_ig(\d+)_to_og(\d+)/', $nm, $m)) {
                    $node['properties']['fpp.inputGroup.route'] = true;
                    $node['properties']['fpp.inputGroup.id'] = intval($m[1]);
                    $node['properties']['fpp.outputGroup.id'] = intval($m[2]);
                }
            }
            unset($node);
        }
    }

    return json(array(
        'nodes' => array_values($nodes),
        'ports' => $ports,
        'links' => $links,
    ));
}
