<?
require_once '../config.php';
require_once '../common/mqtthelp.php';
?>
<script>
    // Fixes a problem because config.php changes helpPage
    helpPage = "help/settings.php";
</script>
<center><b>MQTT Settings</b></center>
<hr>
Your FPP player name is important, commands sent to MQTT have your player name (<code><?echo GetSettingValue('HostName', 'FPP'); ?></code>) in them.  This will be different for each player.
<ul>
<li>MQTT events will be published to <code><?echo GetSettingValue('MQTTPrefix', '', '', '/'); ?>falcon/player/<?echo GetSettingValue('HostName', 'FPP'); ?>/</code> with playlist events being in the <code>playlist</code> subtopic.
<li><b>CA file</b> is the full path to the signer certificate.  Only needed if using an MQTTS server that is self signed.
<li>The <b>Subscribe Topic</b> can be used to bring variables into FPP for Playlist branching or accessing via the REST API: <code>api/fppd/mqtt/cache</code>
<li>FPP will respond to certain events:
</ul>
<div class='fppTableWrapper selectable'>
<div class='fppTableContents' role="region" aria-labelledby="MQTTSettingsTable" tabindex="0">
<?showMqttHelpTable()?>
</div>
</div>
