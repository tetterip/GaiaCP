{{-- The admin panel as an installable app (App\Http\Controllers\PwaController): manifest, icons and the service worker
     that shows push notifications. --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ \App\Http\Controllers\PwaController::THEME_COLOR }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="GaiaCP">
<link rel="apple-touch-icon" href="{{ asset('images/gaiacp/apple-touch-icon.png') }}">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(@js(route('pwa.service-worker')), { scope: @js(url('/admin').'/') }).catch(() => {});
        });
    }
</script>
