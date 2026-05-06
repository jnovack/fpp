<?php
function showMqttHelpTable() {
    $prefix = GetSettingValue('MQTTPrefix', '', '', '/');
    $host   = GetSettingValue('HostName', 'FPP');
    $base   = $prefix . 'falcon/player/' . $host;
?>
<table class="table table-bordered table-hover" id="MQTTSettingsTable">
<thead class="thead-dark">
<tr><th>Topic</th><th>Action</th></tr>
</thead>
<tbody>
<tr>
<td><code><?= $base ?>/set/command/${command}</code></td>
<td>Runs an FPP Command. The payload should be the JSON input given to the REST API for a command, where <code>${command}</code> would be something like <code>Volume Set</code>.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/start</code></td>
<td>Starts the playlist. Optional payload can be the index of the item to start with.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/next</code></td>
<td>Forces playback of the next item in the playlist. Payload is ignored.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/prev</code></td>
<td>Forces playback of the previous item in the playlist. Payload is ignored.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/repeat</code></td>
<td>Turns repeat on if payload is <code>1</code>, otherwise turns repeat off.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/sectionPosition</code></td>
<td>Payload contains an integer position within the playlist (0-based).</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/stop/now</code></td>
<td>Forces the playlist to stop immediately. <code>PLAYLISTNAME</code> can be <code>ALLPLAYLISTS</code>.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/stop/graceful</code></td>
<td>Gracefully stops the playlist. <code>PLAYLISTNAME</code> can be <code>ALLPLAYLISTS</code>.</td>
</tr>
<tr>
<td><code><?= $base ?>/set/playlist/${PLAYLISTNAME}/stop/afterloop</code></td>
<td>Allows the playlist to finish the current loop then stops. <code>PLAYLISTNAME</code> can be <code>ALLPLAYLISTS</code>.</td>
</tr>
<tr>
<td><code><?= $base ?>/event/</code></td>
<td>Starts the event identified by the payload. The payload format is <code>MAJ_MIN</code> identifying the event.</td>
</tr>
<tr>
<td><code><?= $base ?>/effect/start</code></td>
<td>Starts the effect named in the payload.</td>
</tr>
<tr>
<td><code><?= $base ?>/effect/stop</code></td>
<td>Stops the effect named in the payload, or all effects if the payload is empty.</td>
</tr>
<tr>
<td>
<code><?= $base ?>/light/${MODELNAME}/cmd</code><br>
<code><?= $base ?>/light/${MODELNAME}/state</code>
</td>
<td>Controls a Pixel Overlay Model via Home Assistant's MQTT Light interface. The model is treated as an RGB light.</td>
</tr>
</tbody>
</table>
<?
}
?>