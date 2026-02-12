<?
$skipJSsettings = 1;
require_once('common.php');

/////////////////////////////////////////////////////////////////////////////
// Set a sane audio device if not set already
if (
    (!isset($settings['AudioOutput'])) ||
    ($settings['AudioOutput'] == '')
) {

    exec($SUDO . " grep card /root/.asoundrc | head -n 1 | awk '{print $2}'", $output, $return_val);
    if ($return_val) {
        error_log("Error getting currently selected alsa card used!");
    } else {
        if (isset($output[0]))
            $settings['AudioOutput'] = $output[0];
        else
            $settings['AudioOutput'] = "0";
    }
    unset($output);
}

/////////////////////////////////////////////////////////////////////////////
// Set a sane audio mixer device if not set already
if (!isset($settings['AudioMixerDevice'])) {
    if ($settings['BeaglePlatform']) {
        $settings['AudioMixerDevice'] = exec($SUDO . " amixer -c " . $settings['AudioOutput'] . " scontrols | head -1 | cut -f2 -d\"'\"", $output, $return_val);
        if ($return_val) {
            $settings['AudioMixerDevice'] = "PCM";
        }
    } else {
        $settings['AudioMixerDevice'] = "PCM";
    }
}

/////////////////////////////////////////////////////////////////////////////

?>

<?
PrintSettingGroup('generalAudio');

// PipeWire section — always rendered, visibility controlled dynamically by AudioBackend
{
    $cardsJson = @file_get_contents('http://127.0.0.1/api/pipewire/audio/cards');
    $pwCards = $cardsJson ? json_decode($cardsJson, true) : array();
    $currentSink = isset($settings['PipeWireSinkName']) ? $settings['PipeWireSinkName'] : '';

    $groupsFile = $settings['mediaDirectory'] . "/config/pipewire-audio-groups.json";
    $audioGroups = array();
    if (file_exists($groupsFile)) {
        $gData = json_decode(file_get_contents($groupsFile), true);
        if ($gData && isset($gData['groups'])) {
            $audioGroups = $gData['groups'];
        }
    }
    $isPipeWire = (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire');
?>
<div id="pipeWireSection"<?= $isPipeWire ? '' : ' style="display:none;"' ?>>
<h2>PipeWire Audio</h2>
<div class="container-fluid settingsTable settingsGroupTable">
    <div class="row" id="PipeWirePrimaryOutputRow">
        <div class="printSettingLabelCol col-md-4 col-lg-3 col-xxxl-2">
            <div class="description">Primary Audio Output</div>
        </div>
        <div class="printSettingFieldCol col-md">
            <select id="PipeWirePrimaryOutput" class="form-select form-select-sm" style="max-width:400px; display:inline-block;"
                    onChange="PipeWirePrimaryOutputChanged();">
                <option value="">(System Default)</option>
<?php
    $hasGroups = false;
    foreach ($audioGroups as $g) {
        if (!empty($g['members'])) {
            if (!$hasGroups) {
                echo '                <optgroup label="Audio Output Groups">' . "\n";
                $hasGroups = true;
            }
            $nodeName = 'fpp_group_' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($g['name']));
            $sel = ($currentSink === $nodeName) ? ' selected' : '';
            $enabledTag = (isset($g['enabled']) && $g['enabled']) ? '' : ' (disabled)';
            echo '                <option value="' . htmlspecialchars($nodeName) . '"' . $sel . '>'
                . htmlspecialchars($g['name']) . $enabledTag
                . ' (' . count($g['members']) . ' card' . (count($g['members']) !== 1 ? 's' : '') . ')'
                . '</option>' . "\n";
        }
    }
    if ($hasGroups) echo '                </optgroup>' . "\n";

    if (!empty($pwCards)) {
        echo '                <optgroup label="Physical Sound Cards">' . "\n";
        foreach ($pwCards as $c) {
            if (isset($c['isAES67']) && $c['isAES67']) continue;
            $pwNode = isset($c['pwNodeName']) && !empty($c['pwNodeName']) ? $c['pwNodeName'] : '';
            if (empty($pwNode)) continue;
            $sel = ($currentSink === $pwNode) ? ' selected' : '';
            echo '                <option value="' . htmlspecialchars($pwNode) . '"' . $sel . '>'
                . htmlspecialchars($c['cardName'])
                . '</option>' . "\n";
        }
        echo '                </optgroup>' . "\n";
    }
?>
            </select>
            <? PrintToolTip('PipeWirePrimaryOutput'); ?>
        </div>
    </div>
</div>

<script>
function PipeWirePrimaryOutputChanged() {
    var value = $('#PipeWirePrimaryOutput').val();
    SetSetting('PipeWirePrimaryOutput', value, 2, 0, false, null, function() {
        settings['PipeWirePrimaryOutput'] = value;
        $.ajax({
            url: 'api/pipewire/audio/primary-output',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ sinkName: value }),
            dataType: 'json'
        });
    });
}
</script>

<?
    PrintSettingGroup('pipeWireAudio', '', '', 1, '', '', false);
?>
</div>

<div id="alsaHardwareAudioSection"<?= (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire') ? ' style="display:none;"' : '' ?>>
<?
PrintSettingGroup('alsaHardwareAudio');
?>
</div>
<script>
$(document).ready(function() {
    var origChildFn = window.UpdateAudioBackendChildren;
    if (typeof origChildFn === 'function') {
        window.UpdateAudioBackendChildren = function(mode) {
            origChildFn(mode);
            var val = $('#AudioBackend').val();
            if (val === 'pipewire') {
                $('#pipeWireSection').show();
                $('#alsaHardwareAudioSection').hide();
            } else {
                $('#pipeWireSection').hide();
                $('#alsaHardwareAudioSection').show();
            }
        };
    }
});
</script>
<?
}
?>

<?
PrintSettingGroup('generalVideo');
?>