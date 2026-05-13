<?
require_once '../common.php';

/**
 * Configures outbound email using the existing settings.
 *
 * @route POST /api/email/configure
 * @response {"Status": "OK", "Message": ""}
 */
function ConfigureEmail() {
    $result = Array();

    ApplyEmailConfig();

    $result['Status'] = 'OK';
    $result['Message'] = '';

    //Trigger a JSON Configuration Backup
    GenerateBackupViaAPI('Email Configuration was modified.');

    return json($result);
}

/**
 * Sends a test email using the existing settings.
 *
 * @route POST /api/email/test
 * @response {"Status": "OK", "Message": ""}
 */
function SendTestEmail() {
    global $settings;
    $result = Array();
    $result_code = 0;
    $tmpfname = tempnam("/tmp", "sendmail-stderr.txt");

    system('echo "Email test from $(hostname)" | mail -s "Email test from $(hostname)" ' . $settings['emailtoemail'] . " 2> " . $tmpfname, $result_code); //capture stderr

    $result['Status'] = 'OK'; //maybe not; need to check ret code
    $result['Message'] = '';
    $result['result_code'] = $result_code; //DJ
    $result['stderr'] = file_get_contents($tmpfname);
    unlink($tmpfname);

    return json($result);
}

?>
