<?

/////////////////////////////////////////////////////////////////////////////
// GET /api/plugin
function GetInstalledPlugins()
{
	global $settings;
	$plugins = Array();

	$dir = $settings['pluginDirectory'];

	if ($dh = opendir($dir))
	{
		while (($file = readdir($dh)) !== false)
		{
			if ((!in_array($file, array('.', '..'))) &&
				(is_dir($dir . '/' . $file)) &&
				(file_exists($dir . '/' . $file . '/pluginInfo.json')))
			{
				array_push($plugins, $file);
			}
		}
	}

	return json($plugins);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/plugin
function InstallPlugin()
{
	global $settings, $fppDir, $SUDO, $_REQUEST;
	$result = Array();

	$pluginInfoJSON = "";
	$postdata = fopen("php://input", "r");
	while ($data = fread($postdata, 1024*16)) {
		$pluginInfoJSON .= $data;
	}
	fclose($postdata);

	$pluginInfo = json_decode($pluginInfoJSON, true);

	$plugin = escapeshellcmd($pluginInfo['repoName']);
	$srcURL = $pluginInfo['srcURL'];
	$branch = escapeshellcmd($pluginInfo['branch']);
	$sha = $pluginInfo['sha'];
	$infoURL = $pluginInfo['infoURL'];
	$useCredentials = isset($pluginInfo['useCredentials']) && $pluginInfo['useCredentials'];

	if ($useCredentials) {
		$srcURL = InjectGitHubCredentials($srcURL);
		if ($srcURL === false) {
			$result['Status'] = 'Error';
			$result['Message'] = 'Use Credentials was selected but GitHub user name and/or Personal Access Token are not configured on the Developer settings page.';
			return json($result);
		}
	}

    $stream = $_REQUEST['stream'];
    
	if (!file_exists($settings['pluginDirectory'] . '/' . $plugin))
	{
        $return_val = 0;
        if (isset($stream) && $stream != "false") {
            DisableOutputBuffering();
            system("$fppDir/scripts/install_plugin $plugin \"$srcURL\" \"$branch\" \"$sha\"", $return_val);
        } else {
            exec("export SUDO=\"" . $SUDO . "\"; export PLUGINDIR=\"" . $settings['pluginDirectory'] . "\"; $fppDir/scripts/install_plugin $plugin \"$srcURL\" \"$branch\" \"$sha\"", $output, $return_val);
            unset($output);
        }

		if ($return_val == 0)
		{
			$infoFile = $settings['pluginDirectory'] . '/' . $plugin . '/pluginInfo.json';
			if (!file_exists($infoFile))
			{
				// no pluginInfo.json in repository, install the one we
				// installed the plugin from
				$info = $useCredentials ? FetchURLWithGitHubCredentials($infoURL) : file_get_contents($infoURL);
				file_put_contents($infoFile, $info);

				$data = json_decode($info, true);

				if (isset($data['linkName']))
				{
					exec("cd " . $settings['pluginDirectory'] . " && ln -s " . $plugin . " " . $data['linkName'], $output, $return_val);
					unset($output);
				}
			}

            if (isset($stream) && $stream != "false") {
                return "\nDone\n";
            }
			$result['Status'] = 'OK';
			$result['Message'] = '';
		}
		else
		{
			$result['Status'] = 'Error';
			$result['Message'] = 'Could not properly install plugin';
		}
	}
	else
	{
		$result['Status'] = 'Error';
		$result['Message'] = 'The (' . $plugin . ') plugin is already installed';
	}

	return json($result);
}

/////////////////////////////////////////////////////////////////////////////
// GET /api/plugin/:RepoName
function GetPluginInfo()
{
	global $settings;

	$plugin = params('RepoName');
	$infoFile = $settings['pluginDirectory'] . '/' . $plugin . '/pluginInfo.json';

	if (file_exists($infoFile))
	{
		$json = file_get_contents($infoFile);
		$result = json_decode($json, true);
		$result['Status'] = 'OK';
		$result['updatesAvailable'] = PluginHasUpdates($plugin);

		return json($result);
	}

	$result = Array();
	$result['Status'] = 'Error';

	if (!file_exists($settings['pluginDirectory'] . '/' . $plugin))
		$result['Message'] = 'Plugin is not installed';
	else
		$result['Message'] = 'pluginInfo.json does not exist';

	return json($result);
}

/////////////////////////////////////////////////////////////////////////////
// DELETE /api/plugin/:RepoName
function UninstallPlugin()
{
	global $settings, $fppDir, $SUDO, $_REQUEST;
	$result = Array();
    $stream = $_REQUEST['stream'];

	$plugin = params('RepoName');

	if (file_exists($settings['pluginDirectory'] . '/' . $plugin))
	{
		$infoFile = $settings['pluginDirectory'] . '/' . $plugin . '/pluginInfo.json';
		if (file_exists($infoFile))
		{
			$info = file_get_contents($infoFile);

			$data = json_decode($info, true);

			if (isset($data['linkName']))
				exec("rm " . $settings['pluginDirectory'] . "/" . $data['linkName'], $output, $return_val);
		}

        if (isset($stream) && $stream != "false") {
            DisableOutputBuffering();
            system("$fppDir/scripts/uninstall_plugin $plugin", $return_val);
        } else {
		    exec("export SUDO=\"" . $SUDO . "\"; export PLUGINDIR=\"" . $settings['pluginDirectory'] ."\"; $fppDir/scripts/uninstall_plugin $plugin", $output, $return_val);
		    unset($output);
        }

		if ($return_val == 0)
		{
            if (isset($stream) && $stream != "false") {
                return "\nDone\n";
            }
			$result['Status'] = 'OK';
			$result['Message'] = '';
		}
		else
		{
			$result['Status'] = 'Error';
			$result['Message'] = 'Failed to properly uninstall plugin (' . $plugin . ')';
		}
	}
	else
	{
		$result['Status'] = 'Error';
		$result['Message'] = 'The plugin (' . $plugin . ') is not installed';
	}

	return json($result);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/plugin/:RepoName/updates
function CheckForPluginUpdates()
{
	global $settings, $SUDO;
	$result = Array();

	$plugin = params('RepoName');

	$cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && ' . $SUDO . ' git fetch)';
	exec($cmd, $output, $return_val);

	if ($return_val == 0)
	{
		$result['Status'] = 'OK';
		$result['Message'] = '';
		$result['updatesAvailable'] = PluginHasUpdates($plugin);
	}
	else
	{
		$result['Status'] = 'Error';
		$result['Message'] = 'Could not run git fetch for plugin ' . $plugin;
	}

	return json($result);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/plugin/:RepoName/upgrade
// GET /api/plugin/:RepoName/upgrade
function UpgradePlugin()
{
	global $settings, $SUDO, $_REQUEST, $fppDir;
	$result = Array();

	$plugin = params('RepoName');
    $stream = $_REQUEST['stream'];

    if (isset($stream) && $stream != "false") {
        DisableOutputBuffering();
        $cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && ' . $SUDO . ' git pull)';
        system($cmd, $return_val);
        if ($return_val != 0) {
            $cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && ' . $SUDO . ' git clean -fd && ' . $SUDO . ' git pull)';
            system($cmd, $return_val);
        }
        $install_script = $settings['pluginDirectory'] . '/' . $plugin . '/scripts/fpp_install.sh';
        if (!file_exists($install_script)) {
            $install_script = $settings['pluginDirectory'] . '/' . $plugin . '/fpp_install.sh';
        }
        if (file_exists($install_script)) {
            echo "Running install script " . $install_script . "\n";
            system($SUDO . "  FPPDIR=" . $fppDir . " SRCDIR=" . $fppDir . "/src " . $install_script, $return_val);
        }
        return "\nDone\n";
    }
    $cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && ' . $SUDO . ' git pull)';
	exec($cmd, $output, $return_val);
    if ($return_val != 0) {
        $cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && ' . $SUDO . ' git clean -fd && ' . $SUDO . ' git pull)';
        exec($cmd, $output, $return_val);
    }
    $install_script = $settings['pluginDirectory'] . '/' . $plugin . '/scripts/fpp_install.sh';
    if (!file_exists($install_script)) {
        $install_script = $settings['pluginDirectory'] . '/' . $plugin . '/fpp_install.sh';
    }
    if (file_exists($install_script)) {
        exec($SUDO . "  FPPDIR=" . $fppDir . " SRCDIR=" . $fppDir . "/src " . $install_script, $return_val);
    }

	if ($return_val == 0) {
		$result['Status'] = 'OK';
		$result['Message'] = '';
	} else {
		$result['Status'] = 'Error';
		$result['Message'] = 'Could not run git pull for plugin ' . $plugin;
	}

	return json($result);
}

/////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////
// Helper functions

// Inject GitHub credentials (user + Personal Access Token) into a GitHub
// HTTPS URL so git clone / curl can authenticate against private repositories.
// Returns the modified URL on success, or false if credentials are not
// configured or the URL is not a recognized GitHub URL.
function InjectGitHubCredentials($url)
{
	global $settings;

	$user = isset($settings['gitHubUser']) ? trim($settings['gitHubUser']) : '';
	$pat = isset($settings['gitHubPAT']) ? trim($settings['gitHubPAT']) : '';

	if ($user === '' || $pat === '')
		return false;

	// Only inject into github.com / raw.githubusercontent.com URLs to avoid
	// leaking credentials to unrelated hosts.
	if (!preg_match('#^https://(github\.com|raw\.githubusercontent\.com|api\.github\.com)/#i', $url))
		return $url;

	return preg_replace('#^https://#i', 'https://' . rawurlencode($user) . ':' . rawurlencode($pat) . '@', $url, 1);
}

// Fetch the contents of a URL with GitHub credentials. Falls back to
// file_get_contents when credentials are not configured.
function FetchURLWithGitHubCredentials($url)
{
	$authUrl = InjectGitHubCredentials($url);
	if ($authUrl === false)
		return file_get_contents($url);

	if (function_exists('curl_init')) {
		global $settings;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERPWD, $settings['gitHubUser'] . ':' . $settings['gitHubPAT']);
		curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-PluginManager');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github.raw, application/json, */*'));
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}

	return file_get_contents($authUrl);
}

/////////////////////////////////////////////////////////////////////////////
// POST /api/plugin/fetchInfo
// Server-side proxy for fetching a pluginInfo.json from a private GitHub
// repository using the credentials configured on the Developer settings page.
// Body: { "url": "<raw pluginInfo.json URL>", "useCredentials": 1 }
function FetchPluginInfoProxy()
{
	$body = '';
	$fp = fopen('php://input', 'r');
	while ($d = fread($fp, 1024 * 16)) {
		$body .= $d;
	}
	fclose($fp);

	$req = json_decode($body, true);
	$url = isset($req['url']) ? $req['url'] : '';
	$useCreds = isset($req['useCredentials']) && $req['useCredentials'];

	if ($url === '' || !preg_match('#^https://#i', $url)) {
		return json(array('Status' => 'Error', 'Message' => 'Invalid URL'));
	}

	if ($useCreds) {
		$user = isset($GLOBALS['settings']['gitHubUser']) ? trim($GLOBALS['settings']['gitHubUser']) : '';
		$pat = isset($GLOBALS['settings']['gitHubPAT']) ? trim($GLOBALS['settings']['gitHubPAT']) : '';
		if ($user === '' || $pat === '') {
			return json(array('Status' => 'Error', 'Message' => 'GitHub user name and/or Personal Access Token are not configured on the Developer settings page.'));
		}
		$data = FetchURLWithGitHubCredentials($url);
	} else {
		$data = file_get_contents($url);
	}

	if ($data === false || $data === null || $data === '') {
		return json(array('Status' => 'Error', 'Message' => 'Failed to fetch pluginInfo.json from ' . $url));
	}

	$decoded = json_decode($data, true);
	if (!is_array($decoded)) {
		return json(array('Status' => 'Error', 'Message' => 'Response from ' . $url . ' was not valid JSON'));
	}

	return json($decoded);
}

function PluginHasUpdates($plugin)
{
	global $settings;
	$output = '';

	$cmd = '(cd ' . $settings['pluginDirectory'] . '/' . $plugin . ' && git log $(git rev-parse --abbrev-ref HEAD)..origin/$(git rev-parse --abbrev-ref HEAD))';
	exec($cmd, $output, $return_val);

	if (($return_val == 0) && !empty($output))
		return 1;

	return 0;
}

/////////////////////////////////////////////////////////////////////////////

function PluginGetSetting() 
{
	$setting = params("SettingName");
	$plugin  = params("RepoName");

	$value = ReadSettingFromFile($setting, $plugin);

	$result = Array("status" => "OK");
	$result[$setting] = $value;

	return json($result);

}

function PluginSetSetting()
{

	$setting = params("SettingName");
	$plugin  = params("RepoName");
	$value = file_get_contents('php://input');

	WriteSettingToFile($setting, $value, $plugin);

	return PluginGetSetting();
}


?>
