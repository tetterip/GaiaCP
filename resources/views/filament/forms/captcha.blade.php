{{-- A captcha plugin's widget (App\Captcha\Captcha): loads its script, renders it explicitly and puts the token in
     the field's state; reset on "captcha-reset" (a failed login). --}}
@php($statePath = $getStatePath())
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($statePath)),
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
                    // render() is there once the script has loaded, so no ready(): Turnstile throws on it with async scripts.
                    this.id = api.render(this.$refs.box, {
                        ...widget.options,
                        callback: (token) => this.state = token,
                        'expired-callback': () => this.state = null,
                        'error-callback': () => this.state = null,
                    });
                };
                render();
            },
            reset() {
                this.state = null;
                const api = window[@js($widget['global'])];
                if (api && this.id !== null) api.reset(this.id);
            },
        }"
        x-on:captcha-reset.window="reset()"
    >
        <div x-ref="box"></div>
    </div>
</x-dynamic-component>
