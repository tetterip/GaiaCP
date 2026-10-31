{{-- The captcha before the first domain search or WHOIS lookup (Settings → General → Captcha): its token goes to the
     page's $captcha. Needs $widget (the captcha plugin's captchaWidget()) and $key (the wire:key). --}}
<div
    wire:key="{{ $key }}"
    wire:ignore
    x-data="{
        id: null,
        init() {
            const widget = @js($widget);
            if (! document.querySelector(`script[src='${widget.script}']`)) {
                const script = document.createElement('script');
                script.src = widget.script;
                script.async = true;
                script.defer = true;
                document.head.appendChild(script);
            }
            const render = () => {
                const api = window[widget.global];
                if (! api || typeof api.render !== 'function') {
                    return setTimeout(render, 100);
                }
                this.id = api.render(this.$refs.box, {
                    ...widget.options,
                    callback: (token) => $wire.set('captcha', token, false),
                    'expired-callback': () => $wire.set('captcha', null, false),
                    'error-callback': () => $wire.set('captcha', null, false),
                });
            };
            render();
        },
        reset() {
            const api = window[@js($widget['global'])];
            if (api && this.id !== null) api.reset(this.id);
        },
    }"
    x-on:captcha-reset.window="reset()"
>
    <div x-ref="box"></div>
</div>
@error('captcha')
    <p class="gcp-cart-line-error">{{ $message }}</p>
@enderror
