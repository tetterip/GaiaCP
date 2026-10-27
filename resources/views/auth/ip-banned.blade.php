{{-- BlockBannedIps: what a banned address sees in place of the login, sign up and forgot password pages. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Your IP address is blocked') }}</title>
    <style>
        :root { --text: #1f2a24; --muted: #66706a; --line: #e2e5e3; --bg: #f7f8f7; --card: #fff; --red: #a33a2c; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #e6ebe7; --muted: #9aa49e; --line: #2c332f; --bg: #141816; --card: #1b201d; --red: #f08b7c; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 560px; margin: 0 auto; padding: 64px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-left: 4px solid var(--red); border-radius: min(var(--radius, 10px), 14px); padding: 24px; }
        h1 { font-size: 19px; margin: 0 0 12px; overflow-wrap: anywhere; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0 0 16px; font-size: 14px; }
        dt { color: var(--muted); } dd { margin: 0; font-weight: 600; }
        p { color: var(--muted); margin: 0; font-size: 14px; }
        .brand { text-align: center; color: var(--muted); margin-bottom: 20px; font-weight: 600; }
    </style>
    @include('themes.standalone')
</head>
<body>
<main>
    <div class="brand">{{ config('gaiacp.company.name') ?: 'GaiaCP' }}</div>
    <div class="card">
        <h1>{{ __('Your IP address :ip is blocked', ['ip' => $ip]) }}</h1>
        <dl>
            <dt>{{ __('Reason') }}</dt>
            <dd>{{ $ban->reasonText() }}</dd>
            <dt>{{ __('Ban lifted') }}</dt>
            <dd>{{ $ban->expires_at ? $ban->expires_at->timezone(config('app.timezone'))->format(\App\Providers\AppServiceProvider::dateFormat().' (H:i)') : __('When an administrator lifts it') }}</dd>
        </dl>
        <p>{{ __('Logging in from this address is paused. Try again after that time, or contact us if you think this is a mistake.') }}</p>
    </div>
</main>
</body>
</html>
