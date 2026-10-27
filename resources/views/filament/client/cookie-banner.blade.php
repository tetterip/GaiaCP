{{-- App\Support\CookieConsent: the banner, the scripts that wait for "Accept all", and "Cookie settings" to ask again. --}}
@php
    $scripts = \App\Support\CookieConsent::scripts();
    $policy = config('gaiacp.cookies.policy_url');
@endphp
<div id="gaiacp-cookies" role="dialog" aria-live="polite" aria-label="{{ __('Cookies') }}" hidden
     style="position: fixed; inset-inline: 1rem; bottom: 1rem; z-index: 60; max-width: 40rem; margin-inline: auto; padding: 1rem 1.25rem; border-radius: .75rem; background: var(--gray-900, #111827); color: #fff; box-shadow: 0 10px 30px rgb(0 0 0 / .25); font-size: .875rem; line-height: 1.5;">
    <p style="margin: 0 0 .75rem;">
        {{ \App\Support\CookieConsent::text() }}
        @if (filled($policy))
            <a href="{{ $policy }}" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">{{ __('Cookie policy') }}</a>
        @endif
    </p>
    <div style="display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end;">
        @if ($scripts !== null)
            <button type="button" data-consent="necessary" style="padding: .5rem 1rem; border-radius: .5rem; border: 1px solid rgb(255 255 255 / .4); background: transparent; color: inherit; cursor: pointer;">{{ __('Only necessary') }}</button>
            <button type="button" data-consent="all" style="padding: .5rem 1rem; border-radius: .5rem; border: 0; background: var(--primary-600, #2c7a7c); color: #fff; font-weight: 600; cursor: pointer;">{{ __('Accept all') }}</button>
        @else
            <button type="button" data-consent="necessary" style="padding: .5rem 1rem; border-radius: .5rem; border: 0; background: var(--primary-600, #2c7a7c); color: #fff; font-weight: 600; cursor: pointer;">{{ __('OK') }}</button>
        @endif
    </div>
</div>
@if ($scripts !== null)
    <template id="gaiacp-consent-scripts">{!! $scripts !!}</template>
@endif
<script>
    (() => {
        const name = @js(\App\Support\CookieConsent::COOKIE);
        const banner = document.getElementById('gaiacp-cookies');
        const read = () => (document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)')) || [])[1];
        let loaded = false;

        // The waiting scripts, run once: a <script> copied from a <template> would not run by itself
        const load = () => {
            const template = document.getElementById('gaiacp-consent-scripts');
            if (loaded || ! template) return;
            loaded = true;
            template.content.childNodes.forEach((node) => {
                if (node.nodeName !== 'SCRIPT') {
                    document.body.appendChild(node.cloneNode(true));
                    return;
                }
                const script = document.createElement('script');
                [...node.attributes].forEach((attribute) => script.setAttribute(attribute.name, attribute.value));
                script.text = node.text;
                document.body.appendChild(script);
            });
        };

        banner.querySelectorAll('[data-consent]').forEach((button) => button.addEventListener('click', () => {
            const choice = button.dataset.consent;
            const withdrawn = read() === 'all' && choice !== 'all';
            document.cookie = name + '=' + choice + '; max-age=' + ({{ \App\Support\CookieConsent::DAYS }} * 86400) + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            banner.hidden = true;
            if (choice === 'all') load();
            // Scripts already running can't be taken back: start again without them
            if (withdrawn) location.reload();
        }));

        window.gaiacpCookieSettings = () => { banner.hidden = false; };

        if (read() === 'all') load();
        else if (read() !== 'necessary') banner.hidden = false;
    })();
</script>
