<?php
/////////////////////////////////////////////////////////////////////////////
// PipeWire Audio Groups API Controller
//
// Manages audio output groups — collections of sound cards/channels that
// form combined PipeWire sinks. Each group becomes a combine-stream module
// instance with its own virtual sink node.
//
// Config file: $mediaDirectory/config/pipewire-audio-groups.json
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
    global $SUDO;

    $cards = array();
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
                        "byId" => $byId
                    );
                }
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

    // Create combine-stream modules for each group
    $conf .= "context.modules = [\n";

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
            $memberNodeName = $cardNodeMap[$cardId];

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
