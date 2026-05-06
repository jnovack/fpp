<?

require_once('../commandsocket.php');

/**
 * Returns the current FPP schedule configuration from `schedule.json`.
 *
 * @route GET /api/schedule
 * @response [{"day": 7, "enabled": 0, "endDate": "2099-12-31", "endTime": "23:00:00", "playlist": "Main Show", "repeat": 1, "startDate": "2014-01-01", "startTime": "17:00:00", "stopType": 0}]
 */
function GetSchedule() {
    global $settings;

    $file = $settings['configDirectory'] . '/schedule.json';
    if (!file_exists($file)) {
        $schedule = [];
        return json($schedule);
    }

    $data = file_get_contents($file);

    return json(json_decode($data, true));
}

/**
 * Saves the new schedule configuration to `schedule.json`.
 *
 * @route POST /api/schedule
 * @body [{"day": 7, "enabled": 0, "endDate": "2099-12-31", "endTime": "23:00:00", "playlist": "Main Show", "repeat": 1, "startDate": "2014-01-01", "startTime": "17:00:00", "stopType": 0}]
 * @response [{"day": 7, "enabled": 0, "endDate": "2099-12-31", "endTime": "23:00:00", "playlist": "Main Show", "repeat": 1, "startDate": "2014-01-01", "startTime": "17:00:00", "stopType": 0}]
 * @response 404 "Unable to open schedule.json for writing."
 */
function SaveSchedule() {
    global $settings;
    $result = Array();

    $fileName = $settings['configDirectory'] . '/schedule.json';

    $f = fopen($fileName, "w");
    if ($f)
    {
        $postdata = fopen("php://input", "r");
        while ($data = fread($postdata, 1024*16)) {
            fwrite($f, $data);
        }
        fclose($postdata);
        fclose($f);

        //Trigger a JSON Configuration Backup
        GenerateBackupViaAPI('Schedule was modified.');

        return GetSchedule();
    }

    halt(404, 'Unable to open schedule.json for writing.');
}

/**
 * Sends a reload command to `fppd` to re-read the schedule configuration.
 *
 * Requires: `fppd` to be running.
 *
 * @route POST /api/schedule/reload
 * @response {"Status": "OK", "Message": ""}
 */
function ReloadSchedule() {
    SendCommand('R');

    $result = Array();
    $result['Status'] = 'OK';
    $result['Message'] = '';

    return json($result);
}

?>
