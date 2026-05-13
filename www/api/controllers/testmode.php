<?

require_once '../commandsocket.php';

/**
 * Returns the current Test Mode state for this instance.
 *
 * Requires: `fppd` to be running.
 *
 * @route GET /api/testmode
 * @response {"mode": "RGBChase", "subMode": "RGBChase-RGB", "cycleMS": 1000, "colorPattern": "FF000000FF000000FF", "enabled": 1, "channelSet": "1-520", "channelSetType": "channelRange"}
 */
function testMode_Get()
{
	return json(json_decode(SendCommand("GetTestMode")));
}

/**
 * Sets the current Test Mode configuration on this instance.
 *
 * Requires: `fppd` to be running.
 *
 * @route POST /api/testmode
 * @body {"mode": "RGBChase", "subMode": "RGBChase-RGB", "cycleMS": 1000, "colorPattern": "FF000000FF000000FF", "enabled": 1, "channelSet": "1-520", "channelSetType": "channelRange"}
 * @response {"status": "OK"}
 */
function testMode_Set()
{
	global $args;
    $json = strval(file_get_contents('php://input'));
    $input = json_decode($json, true);

	SendCommand(sprintf("SetTestMode,%s", $json));
    return json(json_decode(array("status" => "OK")));
}

?>
