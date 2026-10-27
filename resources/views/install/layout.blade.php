{{-- The web installer's page (InstallController): a plain page, as Filament needs the database and a login. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Install GaiaCP') }}</title>
    {{-- The admin panel's look (the Gaia theme: Commissioner and Literata, soft greys, the logo's teal, rounded cards), in
         light only: the admin's own theme and dark mode need the database, which isn't there yet --}}
    <meta name="color-scheme" content="light">
    <link rel="icon" href="{{ asset('images/gaiacp/mark.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=commissioner:400,500,600,700|literata:500&display=swap">
    <style>
        :root {
            --text: #18181b; --muted: #5b5b63; --line: #e4e4e7; --bg: #f4f4f5; --card: #fff; --soft: #f0f0f2;
            --accent: #2c7a7c; --button: #16302c; --red: #b42318; --amber: #a15c07; --green: #2f7d52;
            --shadow: 0 1px 2px rgba(24, 24, 27, .04), 0 10px 28px -14px rgba(24, 24, 27, .12);
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 Commissioner, system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        main { max-width: 640px; margin: 0 auto; padding: 40px 16px 64px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 24px; }
        .mark { display: flex; align-items: center; gap: 10px; }
        .mark img { height: 2rem; width: auto; display: block; }
        .mark small { color: var(--muted); font-size: 13px; }
        .langs { display: flex; gap: 2px; background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 3px; }
        .langs a { color: var(--muted); text-decoration: none; font-size: 13px; padding: 3px 10px; border-radius: 999px; }
        .langs a.on { background: var(--soft); color: var(--text); font-weight: 600; }
        ol.steps { display: flex; gap: 6px; list-style: none; padding: 0; margin: 0 0 16px; font-size: 13px; color: var(--muted); flex-wrap: wrap; counter-reset: step; }
        ol.steps li { counter-increment: step; display: flex; align-items: center; gap: 6px; padding: 4px 12px 4px 4px; border-radius: 999px; }
        ol.steps li::before { content: counter(step); display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; background: var(--line); color: var(--muted); font-size: 12px; font-weight: 600; }
        ol.steps li.on { background: var(--card); color: var(--text); font-weight: 600; box-shadow: var(--shadow); }
        ol.steps li.on::before { background: var(--accent); color: #fff; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 22px; padding: 32px; box-shadow: var(--shadow); }
        h1 { font-family: Literata, Georgia, serif; font-weight: 500; letter-spacing: -.01em; font-size: 24px; margin: 0 0 6px; }
        p.lead { color: var(--muted); margin: 0 0 20px; }
        a { color: var(--accent); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 10px 4px; border-top: 1px solid var(--line); vertical-align: top; }
        td.state { width: 30px; font-weight: 700; }
        .ok { color: var(--green); } .warn { color: var(--amber); } .err { color: var(--red); }
        .hint { color: var(--muted); font-size: 13px; }
        label { display: block; font-weight: 500; font-size: 14px; margin: 14px 0 6px; }
        input, select { width: 100%; padding: 9px 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); color: var(--text); font: inherit; box-shadow: 0 1px 2px rgba(24, 24, 27, .04); transition: border-color .15s ease, box-shadow .15s ease; }
        input:focus, select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
        .row { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
        fieldset { border: 0; padding: 0; margin: 28px 0 0; }
        fieldset:first-of-type { margin-top: 0; }
        legend { font-family: Literata, Georgia, serif; font-weight: 500; font-size: 17px; padding: 0; }
        .actions { margin-top: 28px; display: flex; justify-content: flex-end; }
        button, .button { background: var(--button); color: #fff; border: 0; border-radius: 12px; padding: 10px 20px; font: inherit; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform .12s ease, opacity .12s ease; }
        button:hover, .button:hover { opacity: .9; } button:active { transform: translateY(1px); }
        button:focus-visible, .button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        button[disabled] { opacity: .45; cursor: not-allowed; }
        .errors { border: 1px solid #fecdca; background: #fef3f2; color: var(--red); border-radius: 12px; padding: 10px 14px; margin-bottom: 18px; font-size: 14px; }
        .errors ul { margin: 0; padding-left: 18px; }
        code.cron { display: block; padding: 12px 14px; background: var(--soft); border: 1px solid var(--line); border-radius: 12px; font-size: 13px; overflow-wrap: anywhere; }
        @media (max-width: 480px) { .row { grid-template-columns: 1fr; } .card { padding: 22px 18px; border-radius: 18px; } ol.steps li:not(.on) { padding-right: 4px; font-size: 0; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
<main>
    <header>
        <span class="mark"><img src="{{ asset('images/gaiacp/logo.svg') }}" alt="GaiaCP"> <small>{{ \App\Support\Version::current() }}@if (\App\Support\Version::stageLabel()) · {{ \App\Support\Version::stageLabel() }}@endif</small></span>
        @if (\App\Support\Locales::multiple() && $step !== 'done') {{-- the installer is closed by then --}}
            <nav class="langs">
                @foreach (\App\Support\Locales::available() as $code => $language)
                    <a href="?lang={{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ $language }}</a>
                @endforeach
            </nav>
        @endif
    </header>

    <ol class="steps">
        @foreach (['requirements' => __('Requirements'), 'database' => __('Database'), 'admin' => __('Business and admin'), 'done' => __('Done')] as $key => $label)
            <li @class(['on' => $step === $key])>{{ $label }}</li>
        @endforeach
    </ol>

    <div class="card">
        @if ($errors->any())
            <div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @yield('content')
    </div>
</main>
</body>
</html>
