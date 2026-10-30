{{-- Panel footer (PanelsRenderHook::FOOTER): "Powered by GaiaCP" under the login form, the copyright line and (in /admin) the version, its stage (Alpha) and a Star on GitHub link on every other page (not on the login page, so visitors don't learn it), and in the client area "Cookie settings" while the cookie banner is on. --}}
<style>
    .gcp-footer { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .25rem .75rem; padding: 1.5rem 1rem; font-size: .75rem; color: var(--gray-500); text-align: center; }
    .gcp-footer-login { flex-direction: column; gap: .35rem; padding-top: 1rem; }
    .gcp-footer a { color: inherit; text-decoration: none; }
    .gcp-footer a:hover { color: var(--primary-600); }
    .gcp-mark { font-weight: 700; letter-spacing: -.01em; color: var(--gray-700); }
    .dark .gcp-mark { color: var(--gray-200); }
    .gcp-footer-login .gcp-mark { font-size: 1rem; }
    .gcp-version { font-variant-numeric: tabular-nums; }
    .gcp-stage { padding: .05rem .4rem; border-radius: 999px; font-size: .65rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--warning-700); background: var(--warning-50); border: 1px solid var(--warning-200); }
    .gcp-footer a.gcp-star { display: inline-flex; align-items: center; gap: .3rem; padding: .1rem .55rem; border-radius: 999px; border: 1px solid var(--gray-300); color: var(--gray-600); }
    .gcp-footer a.gcp-star:hover { color: var(--primary-600); border-color: var(--primary-400); }
    .dark .gcp-footer a.gcp-star { border-color: var(--gray-700); color: var(--gray-300); }
    .gcp-star svg { width: .8rem; height: .8rem; }
    .dark .gcp-stage { color: var(--warning-300); background: transparent; border-color: var(--warning-700); }
</style>

@if (filament()->auth()->check())
    <footer class="gcp-footer">
        <span>© {{ now()->year }} {{ config('gaiacp.company.name') }}. {{ __('All rights reserved.') }}</span>
        <span>{{ __('Powered by') }} @include('filament.gaiacp-mark') @if (filament()->getId() === 'admin')<span class="gcp-version">{{ \App\Support\Version::current() }}</span>@if (\App\Support\Version::stageLabel()) <span class="gcp-stage" title="{{ __('Still in development: things may change between versions, so keep backups.') }}">{{ \App\Support\Version::stageLabel() }}</span>@endif @endif</span>
        @if (filament()->getId() === 'admin')<a class="gcp-star" href="{{ \App\Support\Version::REPOSITORY_URL }}" target="_blank" rel="noopener" title="{{ __('Like GaiaCP? Open it on GitHub and press Star there.') }}"><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 .25a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.75.75 0 0 1-1.088.791L8 12.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L.818 6.374a.75.75 0 0 1 .416-1.28l4.21-.611L7.327.668A.75.75 0 0 1 8 .25Z"/></svg>{{ __('Star on GitHub') }}</a>@endif
        @if (filament()->getId() === 'client' && \App\Support\CookieConsent::enabled())<a href="#" onclick="window.gaiacpCookieSettings?.(); return false;">{{ __('Cookie settings') }}</a>@endif
    </footer>
@else
    <footer class="gcp-footer gcp-footer-login">
        <span>{{ __('Powered by') }}</span>
        @include('filament.gaiacp-mark')
        @if (filament()->getId() === 'client' && \App\Support\CookieConsent::enabled())<a href="#" onclick="window.gaiacpCookieSettings?.(); return false;">{{ __('Cookie settings') }}</a>@endif
    </footer>
@endif
