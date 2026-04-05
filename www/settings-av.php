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
PrintSettingGroup('avModes');

PrintSettingGroup('generalAudio');

// PipeWire section — always rendered, visibility controlled dynamically by AudioBackend
{
    $isPipeWire = (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire');
    ?>
    <div id="pipeWireSection" <?= $isPipeWire ? '' : ' style="display:none;"' ?>>
        <h2>General PipeWire</h2>
        <?
        PrintSettingGroup('pipeWireGeneral', '', '', 1, '', '', false);
        ?>

        <h2>PipeWire Audio</h2>

        <?
        PrintSettingGroup('pipeWireAudio', '', '', 1, '', '', false);
        ?>


    </div>

    <div id="alsaHardwareAudioSection" <?= (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire') ? ' style="display:none;"' : '' ?>>
        <?
        PrintSettingGroup('alsaHardwareAudio');
        ?>
    </div>
    <script>
        $(document).ready(function () {
            var origChildFn = window.UpdateAudioBackendChildren;
            if (typeof origChildFn === 'function') {
                window.UpdateAudioBackendChildren = function (mode) {
                    origChildFn(mode);
                    var val = $('#AudioBackend').val();
                    if (val === 'pipewire') {
                        $('#pipeWireSection').show();
                        $('#pipeWireVideoSection').show();
                        $('#alsaHardwareAudioSection').hide();
                        $('#hardwareDirectVideoSection').hide();
                    } else {
                        $('#pipeWireSection').hide();
                        $('#pipeWireVideoSection').hide();
                        $('#alsaHardwareAudioSection').show();
                        $('#hardwareDirectVideoSection').show();
                    }
                };
            }
        });
    </script>
    <?
}
?>

<div id="pipeWireVideoSection" <?= (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire') ? '' : ' style="display:none;"' ?>>
    <?
    PrintSettingGroup('pipeWireVideo');
    ?>
</div>

<div id="hardwareDirectVideoSection" <?= (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire') ? ' style="display:none;"' : '' ?>>
    <?
    PrintSettingGroup('generalVideo');
    ?>
</div>