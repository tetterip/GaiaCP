{{-- Panel footer (PanelsRenderHook::FOOTER): "Powered by GaiaCP" under the login form, the copyright line and (in /admin) the version (it opens About GaiaCP, filament.about), its stage (Beta) and icon links to the website, GitHub (to star it) and its issues (report a problem) on every other page (not on the login page, so visitors don't learn it), and in the client area "Cookie settings" while the cookie banner is on. --}}
<style>
    .gcp-footer { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .25rem .75rem; padding: 1.5rem 1rem; font-size: .75rem; color: var(--gray-500); text-align: center; }
    .gcp-footer-login { flex-direction: column; gap: .35rem; padding-top: 1rem; }
    .gcp-footer a { color: inherit; text-decoration: none; }
    .gcp-footer a:hover { color: var(--primary-600); }
    .gcp-mark { font-weight: 700; letter-spacing: -.01em; color: var(--gray-700); }
    .dark .gcp-mark { color: var(--gray-200); }
    .gcp-footer-login .gcp-mark { font-size: 1rem; }
    .gcp-version { font-variant-numeric: tabular-nums; }
    .gcp-about-open { padding: 0; border: 0; background: none; font: inherit; color: inherit; cursor: pointer; }
    .gcp-about-open:hover { color: var(--primary-600); text-decoration: underline dotted; text-underline-offset: 2px; }
    .gcp-stage { padding: .05rem .4rem; border-radius: 999px; font-size: .65rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--warning-700); background: var(--warning-50); border: 1px solid var(--warning-200); }
    .gcp-links { display: inline-flex; align-items: center; gap: .15rem; }
    .gcp-footer .gcp-links a { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 999px; color: var(--gray-500); }
    .gcp-footer .gcp-links a:hover { color: var(--primary-600); background: var(--gray-100); }
    .dark .gcp-footer .gcp-links a:hover { color: var(--primary-400); background: var(--gray-800); }
    .gcp-links svg { width: 1rem; height: 1rem; }
    .dark .gcp-stage { color: var(--warning-300); background: transparent; border-color: var(--warning-700); }
</style>

@if (filament()->auth()->check())
    <footer class="gcp-footer">
        <span>© {{ now()->year }} {{ config('gaiacp.company.name') }}. {{ __('All rights reserved.') }}</span>
        <span>{{ __('Powered by') }} @include('filament.gaiacp-mark') @if (filament()->getId() === 'admin')<button type="button" class="gcp-about-open" x-data x-on:click="$dispatch('open-gaiacp-about')" title="{{ __('About GaiaCP') }}"><span class="gcp-version">{{ \App\Support\Version::current() }}</span></button>@if (\App\Support\Version::stageLabel()) <span class="gcp-stage" title="{{ __('Still in development: things may change between versions, so keep backups.') }}">{{ \App\Support\Version::stageLabel() }}</span>@endif @endif</span>
        @if (filament()->getId() === 'admin')
            <span class="gcp-links">
                <a href="{{ \App\Support\Version::WEBSITE_URL }}" target="_blank" rel="noopener" title="{{ __('GaiaCP website') }}" aria-label="{{ __('GaiaCP website') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/></svg></a>
                <a class="gcp-star" href="{{ \App\Support\Version::REPOSITORY_URL }}" target="_blank" rel="noopener" title="{{ __('Like GaiaCP? Open it on GitHub and press Star there.') }}" aria-label="{{ __('Star on GitHub') }}"><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"/></svg></a>
                <a href="{{ \App\Support\Version::ISSUES_URL }}" target="_blank" rel="noopener" title="{{ __('Report a problem or ask a question') }}" aria-label="{{ __('Report a problem or ask a question') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 7V6a4 4 0 0 1 8 0v1M7 9h10v5a5 5 0 0 1-10 0V9ZM12 13v6M3 13h4M17 13h4M4 8l3 2M20 8l-3 2M4 19l3-2M20 19l-3-2"/></svg></a>
            </span>
        @endif
        @if (filament()->getId() === 'client' && \App\Support\CookieConsent::enabled())<a href="#" onclick="window.gaiacpCookieSettings?.(); return false;">{{ __('Cookie settings') }}</a>@endif
    </footer>
@else
    <footer class="gcp-footer gcp-footer-login">
        <span>{{ __('Powered by') }}</span>
        @include('filament.gaiacp-mark')
        @if (filament()->getId() === 'client' && \App\Support\CookieConsent::enabled())<a href="#" onclick="window.gaiacpCookieSettings?.(); return false;">{{ __('Cookie settings') }}</a>@endif
    </footer>
@endif
