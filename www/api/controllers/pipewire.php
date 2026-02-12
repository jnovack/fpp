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
        // Restart PipeWire to pick up removal
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
        exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
        exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
        return json(array("status" => "OK", "message" => "Audio groups cleared, PipeWire restarted"));
    }

    // Generate PipeWire config
    $conf = GeneratePipeWireGroupsConfig($data['groups']);

    // Ensure directory exists
    exec($SUDO . " /bin/mkdir -p /etc/pipewire/pipewire.conf.d");

    // Write via temp file + sudo cp (directory is root-owned)
    $confPath = "/etc/pipewire/pipewire.conf.d/97-fpp-audio-groups.conf";
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_pw_');
    file_put_contents($tmpFile, $conf);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($confPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($confPath));
    unlink($tmpFile);

    // Restart PipeWire services to apply
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
    exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");

    // Wait for PipeWire to be ready
    sleep(2);

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

        // Query the description for this sink (SDL uses descriptions)
        $sinkDesc = '';
        exec($SUDO . " " . $env . " pactl list sinks 2>/dev/null", $sinkOutput);
        $inSink = false;
        foreach ($sinkOutput as $line) {
            if (preg_match('/^\s+Name:\s+(.+)/', $line, $m)) {
                $inSink = (trim($m[1]) === $activeGroup);
            }
            if ($inSink && preg_match('/^\s+Description:\s+(.+)/', $line, $m)) {
                $sinkDesc = trim($m[1]);
                break;
            }
        }

        // Set ForceAudioId so SDL opens the correct device
        WriteSettingToFile('ForceAudioId', $sinkDesc);
        // Set PipeWireSinkName so volume control targets the correct sink
        WriteSettingToFile('PipeWireSinkName', $activeGroup);

        // Tell FPPD to reload settings
        SendCommand('setSetting,ForceAudioId,' . $sinkDesc);
        SendCommand('setSetting,PipeWireSinkName,' . $activeGroup);
    }

    return json(array(
        "status" => "OK",
        "message" => "Audio groups applied, PipeWire restarted",
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
                    if ($byId && isset($pwSinkNames[$byId])) {
                        $pwNodeName = $pwSinkNames[$byId];
                    } elseif ($byPath) {
                        // Strip trailing -audio if present for matching
                        $byPathBase = preg_replace('/-audio$/', '', $byPath);
                        if (isset($pwSinkNames[$byPathBase])) {
                            $pwNodeName = $pwSinkNames[$byPathBase];
                        } elseif (isset($pwSinkNames[$byPath])) {
                            $pwNodeName = $pwSinkNames[$byPath];
                        }
                    }
                    // Fallback: try fpp_card pattern
                    if (empty($pwNodeName) && isset($pwSinkNames['fpp_card' . $cardNum])) {
                        $pwNodeName = $pwSinkNames['fpp_card' . $cardNum];
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

    $eqNodeName = "fpp_eq_g" . $groupId . "_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cardId));

    // Find the filter-chain capture node by name
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
            if ($m[1] === $eqNodeName) {
                $nodeId = $currentId;
                break;
            }
        }
    }

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
    $cmd = $SUDO . " " . $env . " pw-cli set-param " . intval($nodeId) . " Props '{ params = [ $paramStr ] }' 2>&1";
    exec($cmd, $output, $ret);

    if ($ret) {
        return json(array("status" => "ERROR", "message" => "pw-cli set-param failed", "output" => implode("\n", $output)));
    }

    return json(array("status" => "OK"));
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Generate PipeWire combine-stream config from groups
function GeneratePipeWireGroupsConfig($groups)
{
    global $SUDO;

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
    $existingSinks = array(); // node.name => true
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp";
    exec($SUDO . " " . $env . " pw-cli list-objects Node 2>/dev/null", $pwOutput);
    $currentNodeName = null;
    $currentMediaClass = null;
    foreach ($pwOutput as $line) {
        if (preg_match('/node\.name\s*=\s*"(.+?)"/', $line, $m)) {
            $currentNodeName = $m[1];
        }
        if (preg_match('/media\.class\s*=\s*"(.+?)"/', $line, $m)) {
            $currentMediaClass = $m[1];
        }
        // When we hit a new object boundary, store previous
        if (preg_match('/^\s+id \d+,/', $line)) {
            if ($currentNodeName && $currentMediaClass === 'Audio/Sink') {
                $existingSinks[$currentNodeName] = true;
            }
            $currentNodeName = null;
            $currentMediaClass = null;
        }
    }
    // Don't forget the last one
    if ($currentNodeName && $currentMediaClass === 'Audio/Sink') {
        $existingSinks[$currentNodeName] = true;
    }

    // Resolve card IDs and map to existing PipeWire node names.
    // We look for existing sinks that correspond to each card:
    //   1) by-path based name (WirePlumber style): alsa_output.<by-path>.*
    //   2) FPP card0 style name: alsa_output.fpp_card<N>
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

            // Look up which by-path this card has
            $byPath = '';
            $byPathDir = '/dev/snd/by-path';
            if (is_dir($byPathDir)) {
                $entries = scandir($byPathDir);
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..')
                        continue;
                    $target = readlink("$byPathDir/$entry");
                    if ($target !== false && preg_match('/controlC' . $cardNum . '$/', $target)) {
                        $byPath = preg_replace('/-audio$/', '', $entry);
                        break;
                    }
                }
            }

            // Try to find an existing sink for this card
            $foundNode = null;

            // Strategy 1: Match by-path based WirePlumber name
            if ($byPath) {
                $byPathNormalized = str_replace(array(':', '.'), array('-', '-'), $byPath);
                foreach ($existingSinks as $nodeName => $v) {
                    if (
                        strpos($nodeName, $byPathNormalized) !== false ||
                        strpos($nodeName, $byPath) !== false
                    ) {
                        $foundNode = $nodeName;
                        break;
                    }
                }
            }

            // Strategy 2: Match FPP-style name (alsa_output.fpp_cardN)
            if (!$foundNode) {
                $fppName = "alsa_output.fpp_card" . $cardNum;
                if (isset($existingSinks[$fppName])) {
                    $foundNode = $fppName;
                }
            }

            // Strategy 3: Match any sink containing the card number pattern
            if (!$foundNode) {
                foreach ($existingSinks as $nodeName => $v) {
                    // Match patterns like alsa_output.*hw_<cardNum>* or similar
                    if (
                        preg_match('/alsa_output.*[_.]' . preg_quote($cardNum) . '[_.\-]/', $nodeName) ||
                        preg_match('/alsa_output.*card' . preg_quote($cardNum) . '/', $nodeName)
                    ) {
                        $foundNode = $nodeName;
                        break;
                    }
                }
            }

            if ($foundNode) {
                $cardNodeMap[$cardId] = $foundNode;
            } else {
                $unresolvedCards[] = $cardId . " (card $cardNum — no PipeWire sink found)";
            }
        }
    }

    if (!empty($unresolvedCards)) {
        $conf .= "# WARNING: Could not find PipeWire sinks for: " . implode(', ', $unresolvedCards) . "\n";
        $conf .= "# These cards will be skipped from combine groups.\n\n";
    }

    // Create modules array: filter-chain EQ modules first, then combine-stream
    $conf .= "context.modules = [\n";

    // ---------------------------------------------------------------
    // Phase 1: Generate filter-chain modules for members with EQ
    // These must load before combine-stream so their virtual sinks
    // exist when combine-stream scans for matching nodes.
    // ---------------------------------------------------------------
    $eqNodeMap = array();  // "groupId_cardId" -> EQ virtual sink node name
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

            // Check if EQ is enabled with bands
            if (!isset($member['eq']['enabled']) || !$member['eq']['enabled'])
                continue;
            if (!isset($member['eq']['bands']) || empty($member['eq']['bands']))
                continue;

            $bands = $member['eq']['bands'];
            $memberChannels = isset($member['channels']) ? intval($member['channels']) : 2;
            $numCh = min($memberChannels, count($channelLabels));
            $positions = isset($channelPositionArrays[$memberChannels]) ? $channelPositionArrays[$memberChannels] : $channelPositionArrays[2];
            $posStr = "[ " . implode(" ", $positions) . " ]";

            $realNodeName = $cardNodeMap[$cardId];
            $eqNodeName = "fpp_eq_g" . $groupId . "_" . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cardId));
            $eqOutName = $eqNodeName . "_out";
            $eqKey = $groupId . "_" . $cardId;

            $conf .= "  # EQ filter chain for: " . (isset($member['cardName']) ? $member['cardName'] : $cardId) . " (Group $groupId)\n";
            $conf .= "  { name = libpipewire-module-filter-chain\n";
            $conf .= "    args = {\n";
            $conf .= "      node.description = \"EQ: " . (isset($member['cardName']) ? $member['cardName'] : $cardId) . "\"\n";
            $conf .= "      filter.graph = {\n";
            $conf .= "        nodes = [\n";

            // Create biquad nodes for each channel x each band
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

            $conf .= "        ]\n";

            // Links: chain bands in series for each channel
            $conf .= "        links = [\n";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];
                for ($bi = 1; $bi < count($bands); $bi++) {
                    $prevBi = $bi - 1;
                    $conf .= "          { output = \"eq_{$chLabel}_{$prevBi}:Out\" input = \"eq_{$chLabel}_{$bi}:In\" }\n";
                }
            }
            $conf .= "        ]\n";

            // Inputs: first band of each channel
            $conf .= "        inputs = [";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];
                $conf .= " \"eq_{$chLabel}_0:In\"";
            }
            $conf .= " ]\n";

            // Outputs: last band of each channel
            $lastBi = count($bands) - 1;
            $conf .= "        outputs = [";
            for ($ch = 0; $ch < $numCh; $ch++) {
                $chLabel = $channelLabels[$ch];
                $conf .= " \"eq_{$chLabel}_{$lastBi}:Out\"";
            }
            $conf .= " ]\n";

            $conf .= "      }\n"; // filter.graph

            // Capture props (virtual sink that combine-stream will match)
            $conf .= "      capture.props = {\n";
            $conf .= "        node.name = \"$eqNodeName\"\n";
            $conf .= "        media.class = Audio/Sink\n";
            $conf .= "        audio.channels = $numCh\n";
            $conf .= "        audio.position = $posStr\n";
            $conf .= "      }\n";

            // Playback props (output to real sink)
            $conf .= "      playback.props = {\n";
            $conf .= "        node.name = \"$eqOutName\"\n";
            $conf .= "        node.passive = true\n";
            $conf .= "        node.target = \"$realNodeName\"\n";
            $conf .= "        stream.dont-remix = true\n";
            $conf .= "        audio.channels = $numCh\n";
            $conf .= "        audio.position = $posStr\n";
            $conf .= "      }\n";

            $conf .= "    }\n"; // args
            $conf .= "  }\n";

            $eqNodeMap[$eqKey] = $eqNodeName;
        }
    }

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
    global $settings, $SUDO;

    $configFile = $settings['mediaDirectory'] . "/config/pipewire-aes67-instances.json";
    $rtpConfPath = "/etc/pipewire/pipewire.conf.d/96-fpp-aes67-rtp.conf";
    $sapConfPath = "/etc/pipewire/pipewire.conf.d/96-fpp-aes67-sap.conf";
    $ptp4lConfPath = "/etc/ptp4l-fpp.conf";

    exec($SUDO . " /bin/mkdir -p /etc/pipewire/pipewire.conf.d");

    if (!file_exists($configFile)) {
        // Clean up
        foreach (array($rtpConfPath, $sapConfPath) as $f) {
            if (file_exists($f))
                exec($SUDO . " rm -f " . escapeshellarg($f));
        }
        RestartPipeWire();
        return json(array("status" => "OK", "message" => "No AES67 instances configured"));
    }

    $data = json_decode(file_get_contents($configFile), true);
    if ($data === null || !isset($data['instances']) || empty($data['instances'])) {
        foreach (array($rtpConfPath, $sapConfPath) as $f) {
            if (file_exists($f))
                exec($SUDO . " rm -f " . escapeshellarg($f));
        }
        exec("/usr/bin/killall ptp4l 2>/dev/null");
        RestartPipeWire();
        return json(array("status" => "OK", "message" => "AES67 instances cleared"));
    }

    $ptpEnabled = isset($data['ptpEnabled']) ? $data['ptpEnabled'] : true;
    $ptpInterface = isset($data['ptpInterface']) ? $data['ptpInterface'] : '';

    // Generate RTP module config for all enabled instances
    $conf = GenerateAES67Config($data['instances']);

    // Generate SAP config if any instance has SAP enabled
    $sapConf = GenerateAES67SAPConfig($data['instances'], isset($data['ptpInterface']) ? $data['ptpInterface'] : '');

    // Write RTP config
    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_aes67_');
    file_put_contents($tmpFile, $conf);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($rtpConfPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($rtpConfPath));
    unlink($tmpFile);

    // Write or remove SAP config
    if (!empty($sapConf)) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_sap_');
        file_put_contents($tmpFile, $sapConf);
        exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($sapConfPath));
        exec($SUDO . " chmod 644 " . escapeshellarg($sapConfPath));
        unlink($tmpFile);
    } else {
        if (file_exists($sapConfPath))
            exec($SUDO . " rm -f " . escapeshellarg($sapConfPath));
    }

    // PTP clock sync
    if ($ptpEnabled) {
        $ptpIface = empty($ptpInterface) ? 'eth0' : $ptpInterface;
        SetupPTP($ptpIface, $ptp4lConfPath);
    } else {
        exec("/usr/bin/killall ptp4l 2>/dev/null");
        if (file_exists($ptp4lConfPath))
            exec($SUDO . " rm -f " . escapeshellarg($ptp4lConfPath));
    }

    // Restart PipeWire
    RestartPipeWire();

    return json(array(
        "status" => "OK",
        "message" => "AES67 instances applied, PipeWire restarted",
        "restartRequired" => true
    ));
}

// GET /api/pipewire/aes67/status
function GetAES67Status()
{
    global $SUDO;
    $env = "PIPEWIRE_RUNTIME_DIR=/run/pipewire-fpp XDG_RUNTIME_DIR=/run/pipewire-fpp PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse";

    $sinks = array();
    $sources = array();

    exec($SUDO . " " . $env . " pactl list sinks short 2>/dev/null", $sinkOut);
    if (!empty($sinkOut)) {
        foreach ($sinkOut as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2 && strpos($parts[1], 'aes67_') === 0) {
                $sinks[] = $parts[1];
            }
        }
    }

    exec($SUDO . " " . $env . " pactl list sources short 2>/dev/null", $srcOut);
    if (!empty($srcOut)) {
        foreach ($srcOut as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2 && strpos($parts[1], 'aes67_') === 0) {
                $sources[] = $parts[1];
            }
        }
    }

    // PTP status
    $ptpRunning = false;
    exec("pgrep ptp4l 2>/dev/null", $ptpProc);
    $ptpRunning = !empty($ptpProc);

    return json(array(
        "sinks" => $sinks,
        "sources" => $sources,
        "ptpRunning" => $ptpRunning
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


/////////////////////////////////////////////////////////////////////////////
// Helper: Generate PipeWire RTP module config from AES67 instances
function GenerateAES67Config($instances)
{
    $channelPositions = array(
        1 => '[ MONO ]',
        2 => '[ FL FR ]',
        4 => '[ FL FR RL RR ]',
        6 => '[ FL FR FC LFE RL RR ]',
        8 => '[ FL FR FC LFE RL RR SL SR ]'
    );

    $conf = "# Auto-generated by FPP – AES67 multi-instance RTP configuration\n";
    $conf .= "context.modules = [\n";

    foreach ($instances as $inst) {
        if (!isset($inst['enabled']) || !$inst['enabled'])
            continue;

        $nodeSafeName = 'aes67_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($inst['name']));
        $mode = isset($inst['mode']) ? $inst['mode'] : 'send';
        $ip = isset($inst['multicastIP']) ? $inst['multicastIP'] : '239.69.0.1';
        $port = isset($inst['port']) ? intval($inst['port']) : 5004;
        $channels = isset($inst['channels']) ? intval($inst['channels']) : 2;
        $sessionName = isset($inst['sessionName']) ? $inst['sessionName'] : $inst['name'];
        $latency = isset($inst['latency']) ? intval($inst['latency']) : 10;
        $iface = isset($inst['interface']) ? $inst['interface'] : '';
        $audioPos = isset($channelPositions[$channels]) ? $channelPositions[$channels] : '[ FL FR ]';

        $wantSend = ($mode === 'send' || $mode === 'both');
        $wantRecv = ($mode === 'receive' || $mode === 'both');

        if ($wantSend) {
            $conf .= "  { name = libpipewire-module-rtp-sink\n";
            $conf .= "    args = {\n";
            if (!empty($iface)) {
                $conf .= "      local.ifname = \"$iface\"\n";
            }
            $conf .= "      destination.ip = \"$ip\"\n";
            $conf .= "      destination.port = $port\n";
            $conf .= "      net.ttl = 4\n";
            $conf .= "      sess.name = \"$sessionName\"\n";
            $conf .= "      sess.min-ptime = 1\n";
            $conf .= "      sess.max-ptime = 4\n";
            $conf .= "      audio.format = \"S24BE\"\n";
            $conf .= "      audio.rate = 48000\n";
            $conf .= "      audio.channels = $channels\n";
            $conf .= "      audio.position = $audioPos\n";
            $conf .= "      stream.props = {\n";
            $conf .= "        node.name = \"{$nodeSafeName}_send\"\n";
            $conf .= "        node.description = \"" . addcslashes($sessionName, '"') . " (Send)\"\n";
            $conf .= "        media.class = \"Audio/Sink\"\n";
            $conf .= "        sess.sap.announce = true\n";
            $conf .= "      }\n";
            $conf .= "    }\n";
            $conf .= "  }\n";
        }

        if ($wantRecv) {
            $conf .= "  { name = libpipewire-module-rtp-source\n";
            $conf .= "    args = {\n";
            if (!empty($iface)) {
                $conf .= "      local.ifname = \"$iface\"\n";
            }
            $conf .= "      source.ip = \"$ip\"\n";
            $conf .= "      source.port = $port\n";
            $conf .= "      sess.latency.msec = $latency\n";
            $conf .= "      audio.format = \"S24BE\"\n";
            $conf .= "      audio.rate = 48000\n";
            $conf .= "      audio.channels = $channels\n";
            $conf .= "      audio.position = $audioPos\n";
            $conf .= "      stream.props = {\n";
            $conf .= "        node.name = \"{$nodeSafeName}_recv\"\n";
            $conf .= "        node.description = \"" . addcslashes($sessionName, '"') . " (Receive)\"\n";
            $conf .= "        media.class = \"Audio/Source\"\n";
            $conf .= "      }\n";
            $conf .= "    }\n";
            $conf .= "  }\n";
        }
    }

    $conf .= "]\n";
    return $conf;
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Generate SAP module config for AES67 instances
function GenerateAES67SAPConfig($instances, $defaultIface)
{
    $anySAP = false;
    $latency = 10;
    $iface = $defaultIface;

    foreach ($instances as $inst) {
        if (!isset($inst['enabled']) || !$inst['enabled'])
            continue;
        if (isset($inst['sapEnabled']) && $inst['sapEnabled']) {
            $anySAP = true;
            if (isset($inst['latency']))
                $latency = intval($inst['latency']);
            if (!empty($inst['interface']))
                $iface = $inst['interface'];
        }
    }

    if (!$anySAP)
        return '';

    $conf = "# Auto-generated by FPP – AES67 SAP configuration\n";
    $conf .= "context.modules = [\n";
    $conf .= "  { name = libpipewire-module-rtp-sap\n";
    $conf .= "    args = {\n";
    if (!empty($iface)) {
        $conf .= "      local.ifname = \"$iface\"\n";
    }
    $conf .= "      sap.ip = \"224.2.127.254\"\n";
    $conf .= "      sap.port = 9875\n";
    $conf .= "      net.ttl = 4\n";
    $conf .= "      stream.rules = [\n";
    $conf .= "        { matches = [\n";
    $conf .= "            { sess.sap.announce = true }\n";
    $conf .= "          ]\n";
    $conf .= "          actions = {\n";
    $conf .= "            announce-stream = {}\n";
    $conf .= "          }\n";
    $conf .= "        }\n";
    $conf .= "        { matches = [\n";
    $conf .= "            { rtp.session = \"~.*\" }\n";
    $conf .= "          ]\n";
    $conf .= "          actions = {\n";
    $conf .= "            create-stream = {\n";
    $conf .= "              sess.latency.msec = $latency\n";
    $conf .= "            }\n";
    $conf .= "          }\n";
    $conf .= "        }\n";
    $conf .= "      ]\n";
    $conf .= "    }\n";
    $conf .= "  }\n";
    $conf .= "]\n";
    return $conf;
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Set up PTP clock sync
function SetupPTP($iface, $confPath)
{
    global $SUDO;

    $ptpConf = "# Auto-generated by FPP – PTP config for AES67\n"
        . "[global]\n"
        . "domainNumber 0\n"
        . "logging_level 6\n"
        . "slaveOnly 1\n"
        . "clock_servo linreg\n"
        . "network_transport UDPv4\n"
        . "time_stamping hardware\n";

    $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_ptp_');
    file_put_contents($tmpFile, $ptpConf);
    exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($confPath));
    exec($SUDO . " chmod 644 " . escapeshellarg($confPath));
    unlink($tmpFile);

    // Try hardware timestamping first; fall back to software
    exec($SUDO . " /usr/sbin/ptp4l -i " . escapeshellarg($iface) . " -f " . escapeshellarg($confPath) . " -m -q --step_threshold=1 2>&1 & sleep 2 && kill %1 2>/dev/null; wait 2>/dev/null", $testOutput);
    $testResult = implode("\n", $testOutput);
    if (strpos($testResult, 'ioctl PTP_CLOCK_GETCAPS') !== false || strpos($testResult, 'no such device') !== false) {
        $ptpConf = str_replace('time_stamping hardware', 'time_stamping software', $ptpConf);
        $tmpFile = tempnam(sys_get_temp_dir(), 'fpp_ptp_');
        file_put_contents($tmpFile, $ptpConf);
        exec($SUDO . " cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($confPath));
        exec($SUDO . " chmod 644 " . escapeshellarg($confPath));
        unlink($tmpFile);
    }

    exec("/usr/bin/killall ptp4l 2>/dev/null");
    exec($SUDO . " /usr/sbin/ptp4l -i " . escapeshellarg($iface) . " -f " . escapeshellarg($confPath) . " -m > /var/log/ptp4l-fpp.log 2>&1 &");
}

/////////////////////////////////////////////////////////////////////////////
// Helper: Restart PipeWire services
function RestartPipeWire()
{
    global $SUDO;
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire.service 2>&1");
    sleep(1);
    exec($SUDO . " /usr/bin/systemctl restart fpp-wireplumber.service 2>&1");
    sleep(1);
    exec($SUDO . " /usr/bin/systemctl restart fpp-pipewire-pulse.service 2>&1");
    sleep(1);
}
