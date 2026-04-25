<!-- HTML META SECTION -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<script>
  (function () {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    document.documentElement.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light');
    mq.addEventListener('change', function (e) {
      document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
    });
  })();
</script>
