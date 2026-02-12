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

<script>
</script>


<?
PrintSettingGroup('generalAudio');
PrintSettingGroup('alsaHardwareAudio');
PrintSettingGroup('pipewireAudio');

// PipeWire Audio Groups button — only shown when PipeWire backend is active
if (isset($settings['AudioBackend']) && $settings['AudioBackend'] == 'pipewire') {
    ?>
    <div class="callout callout-info"
        style="margin-top:0.5rem; padding:0.75rem 1rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
        <div>
            <i class="fas fa-layer-group"></i>
            <b>Audio Output Groups</b> &mdash; Combine multiple sound cards into virtual sinks with per-card EQ and channel
            mapping.
        </div>
        <button class="btn btn-success btn-sm" onclick="OpenPipeWireAudioGroups()">
            <i class="fas fa-sliders-h"></i> Configure Audio Groups
        </button>
    </div>

    <script>
        function OpenPipeWireAudioGroups() {
            DoModalDialog({
                id: 'pipewireAudioGroupsDlg',
                title: '<i class="fas fa-layer-group"></i> PipeWire Audio Output Groups',
                body: '<iframe src="pipewire-audio.php?modal=1" style="width:100%;height:100%;border:none;"></iframe>',
                open: function () {
                    var dlg = $('#pipewireAudioGroupsDlg');
                    dlg.find('.modal-dialog').addClass('modal-fullscreen');
                    dlg.find('.modal-content').css({ 'background': '#fff', 'color': '#212529' });
                    dlg.find('.modal-body').css({ 'padding': '0', 'overflow': 'hidden' });
                    dlg.find('.modal-header').css({ 'background': '#fff', 'color': '#212529' });
                },
                buttons: {
                    Close: function () {
                        bootstrap.Modal.getInstance(document.getElementById('pipewireAudioGroupsDlg')).hide();
                    }

                }
            });
        }
    </script>
    <?
}

PrintSettingGroup('generalVideo');
?>