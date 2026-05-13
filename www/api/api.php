<?php
    $skipJSsettings = 1;
    require_once '../config.php';
    require_once '../common.php';
    // menuHead.inc redirects to initialSetup.php when this key is absent.
    // API docs must always be accessible, so bypass the guard if unset.
    if (!isset($settings['initialSetup-02'])) {
        $settings['initialSetup-02'] = '1';
    }
?>
<!DOCTYPE html>
<html>

<head>
    <base href="/">
    <?php
        include '../common/htmlMeta.inc';
        include '../common/menuHead.inc';
    ?>
    <style>
        .pageContent { padding: 0; }
        #scalar-frame { display: block; width: 100%; height: calc(100vh - 12em); border: none; }
    </style>
</head>

<body>
    <div id="bodyWrapper">
        <?php
            $activeParentMenuItem = 'help';
            include '../menu.inc';
        ?>
        <div class="mainContainer">
            <h1 class="title">API Documentation<a href="/api/api.html" target="_blank"><i class="fa-solid fa-2xs fa-up-right-from-square ms-2"></i></a></h1>

            <div class="pageContent">
            <!-- END HEADER STRUCTURE -->

                <iframe id="scalar-frame" src="/api/api.html"></iframe>

            <!-- BEGIN FOOTER STRUCTURE -->
            </div>
        </div>
    </div>
    <?php // include '../common/footer.inc'; ?>
    <script>
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });

        document.querySelectorAll('.fpp-help-popover').forEach(function (icon) {
            var contentEl = document.getElementById(icon.dataset.helpContent);
            if (contentEl) {
                new bootstrap.Popover(icon, {
                    title: icon.dataset.helpTitle || '',
                    content: contentEl.innerHTML,
                    html: true,
                    trigger: 'hover focus',
                    placement: 'bottom',
                    sanitize: false
                });
            }
        });
    </script>
</body>
</html>
