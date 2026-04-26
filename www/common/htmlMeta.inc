<!-- HTML META SECTION -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<?php
// htmlMeta.inc is included before config.php, so $settings is not yet
// populated. Read the themeOverride value directly from the settings file
// so we can apply the chosen theme before any UI is rendered (avoids a
// flash of the wrong theme on initial load).
$_fpp_themeOverride = '';
$_fpp_mediaDir = '';
$_fpp_mediaRootFile = __DIR__ . '/../media_root.txt';
if (file_exists($_fpp_mediaRootFile)) {
  $_fpp_mediaDir = trim(file_get_contents($_fpp_mediaRootFile));
} elseif (is_dir('/home/fpp/media')) {
  $_fpp_mediaDir = '/home/fpp/media';
}
if ($_fpp_mediaDir !== '' && is_readable($_fpp_mediaDir . '/settings')) {
  foreach (file($_fpp_mediaDir . '/settings', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_fpp_line) {
    if (preg_match('/^\s*themeOverride\s*=\s*"?([^"\r\n]*)"?\s*$/', $_fpp_line, $_fpp_m)) {
      $_fpp_themeOverride = trim($_fpp_m[1]);
      break;
    }
  }
}
?>
<script>
  (function () {
    var override = <?= json_encode($_fpp_themeOverride) ?>;
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    function applyTheme() {
      var theme;
      if (override === 'light' || override === 'dark') {
        theme = override;
      } else {
        theme = mq.matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-bs-theme', theme);
    }
    applyTheme();
    if (mq.addEventListener) {
      mq.addEventListener('change', applyTheme);
    } else if (mq.addListener) {
      mq.addListener(applyTheme);
    }
  })();
</script>