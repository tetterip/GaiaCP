{{-- Over the login form of the public demo: the account filled in, and the other panel's. --}}
@php
    $admin = filament()->getId() !== 'client';
@endphp
<div style="padding: .75rem 1rem; border-radius: .5rem; font-size: .875rem; line-height: 1.5; background: color-mix(in oklab, var(--primary-500) 10%, transparent); border: 1px solid color-mix(in oklab, var(--primary-500) 30%, transparent);">
    <p>{{ $admin ? __('The demo admin is filled in: just press Sign in.') : __('The demo client is filled in: just press Sign in.') }}</p>
    <p style="margin-top: .25rem; opacity: .8;">
        {{ __('Email') }}: <strong>{{ $admin ? \App\Support\Demo::ADMIN_EMAIL : \App\Support\Demo::CLIENT_EMAIL }}</strong>
        · {{ __('Password') }}: <strong>{{ \App\Support\Demo::PASSWORD }}</strong>
    </p>
    <p style="margin-top: .25rem;">
        @if ($admin)
            <a href="{{ \Filament\Facades\Filament::getPanel('client')->getLoginUrl() }}" style="font-weight: 600; text-decoration: underline;">{{ __('See the client area instead') }}</a>
        @else
            <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}" style="font-weight: 600; text-decoration: underline;">{{ __('See the admin panel instead') }}</a>
        @endif
    </p>
</div>
