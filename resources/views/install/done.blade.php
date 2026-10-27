@extends('install.layout', ['step' => 'done'])

@section('content')
    <h1>{{ __('GaiaCP is installed') }}</h1>
    <p class="lead">{{ __('The installer is now locked. One more thing: add this cron job in your hosting panel, so the daily automation (payment notices, reminders, suspensions, domain syncs) runs. It runs every minute.') }}</p>

    <code class="cron">{{ $cron }}</code>
    <p class="hint">{{ __('Settings → System health shows whether cron is running.') }}</p>
    <p>{{ __('After you log in, the setup guide on the dashboard takes you through the rest: your business, mail, payment methods, a server and your first product.') }}</p>

    <div class="actions"><a class="button" href="{{ $login }}">{{ __('Log in') }}</a></div>
@endsection
