<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1B8048">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="GMAO">
<link rel="apple-touch-icon" href="img/pwa/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="img/pwa/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="img/pwa/favicon-16.png">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('service-worker.js').catch(function () {});
    });
}
</script>
