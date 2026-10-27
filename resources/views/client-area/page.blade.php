{{-- A page of its own outside the client area panel (maintenance, unsubscribe): the company logo over one card. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    @if ($favicon = \App\Support\Branding::url('favicon'))
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <style>
        :root { --text: #1f2a24; --muted: #66706a; --line: #e2e5e3; --bg: #f7f8f7; --card: #fff; --accent: #2f7d55; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #e6ebe7; --muted: #9aa49e; --line: #2c332f; --bg: #141816; --card: #1b201d; --accent: #6cc495; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: @yield('width', '560px'); margin: 0 auto; padding: 64px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: min(var(--radius, 10px), 14px); padding: 24px; }
        h1 { font-size: 19px; margin: 0 0 12px; overflow-wrap: anywhere; }
        p { color: var(--muted); margin: 0; overflow-wrap: anywhere; }
        button { margin-top: 16px; padding: 9px 16px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .brand { text-align: center; color: var(--muted); margin-bottom: 20px; font-weight: 600; }
        .brand img { max-height: 48px; max-width: 100%; }
        .brand .dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .brand.has-dark .light { display: none; }
            .brand.has-dark .dark { display: inline; }
        }
    </style>
    @include('themes.standalone')
    @yield('head')
</head>
<body>
<main>
    @php($logo = \App\Support\Branding::url('logo'))
    @php($logoDark = \App\Support\Branding::url('logo-dark'))
    <div class="brand {{ $logoDark ? 'has-dark' : '' }}">
        @if ($logo)
            <img class="light" src="{{ $logo }}" alt="{{ config('gaiacp.company.name') ?: 'GaiaCP' }}">
            @if ($logoDark)
                <img class="dark" src="{{ $logoDark }}" alt="{{ config('gaiacp.company.name') ?: 'GaiaCP' }}">
            @endif
        @else
            {{ config('gaiacp.company.name') ?: 'GaiaCP' }}
        @endif
    </div>
    <div class="card">
        @yield('card')
    </div>
</main>
</body>
</html>
