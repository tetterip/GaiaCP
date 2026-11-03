@php
    use App\Filament\Pages\Help;
    use App\Filament\Pages\Updates;
    use App\Support\About;
    use App\Support\Version;
@endphp

{{-- About GaiaCP (App\Support\About): opened from the version in the admin footer or the user menu. Also listens for
     the old cheat code (About::SECRET) anywhere in /admin, or About::TAPS taps on the hexagon in this window, and then
     opens Gaia's family tree (filament.about-family). Nothing is saved or sent. --}}
<div
    x-data="{
        open: false,
        family: false,
        secret: @js(About::SECRET),
        step: 0,
        taps: 0,

        typing(event) {
            const el = event.target

            return el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
        },

        // A letter also counts by its place on the keyboard, so the code works with a Greek layout too
        matches(event, expected) {
            return event.key === expected
                || event.key.toLowerCase() === expected
                || (expected.length === 1 && event.code === 'Key' + expected.toUpperCase())
        },

        listen(event) {
            if (event.ctrlKey || event.metaKey || event.altKey || this.typing(event)) {
                return
            }

            if (this.matches(event, this.secret[this.step])) {
                this.step++
            } else {
                this.step = this.matches(event, this.secret[0]) ? 1 : 0
            }

            if (this.step === this.secret.length) {
                this.step = 0
                this.play()
            }
        },

        tap() {
            if (++this.taps >= {{ About::TAPS }}) {
                this.taps = 0
                this.play()
            }
        },

        play() {
            this.open = false
            this.family = true
        },
    }"
    x-on:keydown.window="listen($event)"
    x-on:keydown.escape.window="open = false; family = false"
    x-on:open-gaiacp-about.window="open = true; taps = 0"
    class="gcp-about"
>
    <style>
        .gcp-about-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgb(0 0 0 / 0.5); }
        .gcp-about-panel { position: relative; width: 100%; max-width: 24rem; max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 0.75rem; padding: 1.5rem; background: #fff; color: var(--gray-950); text-align: center; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.2); }
        .dark .gcp-about-panel { background: var(--gray-900); color: #fff; }
        .gcp-about-close { position: absolute; top: 0.6rem; inset-inline-end: 0.6rem; width: 2rem; height: 2rem; border-radius: 999px; color: var(--gray-500); font-size: 1.25rem; line-height: 1; }
        .gcp-about-close:hover { color: var(--gray-700); background: var(--gray-100); }
        .dark .gcp-about-close:hover { color: var(--gray-200); background: var(--gray-800); }
        .gcp-about-mark { display: block; margin: 0 auto 0.5rem; padding: 0; border: 0; background: none; cursor: default; -webkit-tap-highlight-color: transparent; }
        .gcp-about-mark img { width: 4rem; height: 4rem; transition: transform .15s; }
        .gcp-about-mark:active img { transform: scale(.94); }
        .gcp-about-panel h2 { font-size: 1.25rem; font-weight: 700; letter-spacing: -.01em; }
        .gcp-about-version { margin-top: 0.15rem; font-size: 0.875rem; color: var(--gray-500); font-variant-numeric: tabular-nums; }
        .gcp-about-what { margin: 0.75rem 0 1rem; font-size: 0.875rem; color: var(--gray-600); }
        .dark .gcp-about-what { color: var(--gray-300); }
        .gcp-about-stack { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.35rem; margin-bottom: 1rem; }
        .gcp-about-stack span { padding: 0.15rem 0.55rem; border-radius: 999px; border: 1px solid var(--gray-200); font-size: 0.75rem; color: var(--gray-600); font-variant-numeric: tabular-nums; }
        .dark .gcp-about-stack span { border-color: var(--gray-700); color: var(--gray-300); }
        .gcp-about-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.25rem 1rem; font-size: 0.875rem; }
        .gcp-about-links a { color: var(--primary-600); }
        .gcp-about-links a:hover { text-decoration: underline; }
        .dark .gcp-about-links a { color: var(--primary-400); }
        .gcp-about-licence { margin-top: 1rem; font-size: 0.75rem; color: var(--gray-500); }
    </style>

    <template x-teleport="body">
        <div
            x-show="open"
            x-transition.opacity
            x-on:click.self="open = false"
            class="gcp-about-backdrop"
            style="display: none"
            {{-- on the element x-show hides: Filament skips its own shortcuts while a visible aria-modal is open --}}
            role="dialog"
            aria-modal="true"
            aria-labelledby="gcp-about-title"
        >
            <div class="gcp-about-panel">
                <button type="button" class="gcp-about-close" x-on:click="open = false" aria-label="{{ __('Close') }}">×</button>

                <button type="button" class="gcp-about-mark" x-on:click="tap()" tabindex="-1" aria-hidden="true">
                    <img src="{{ asset('images/gaiacp/mark.svg') }}" alt="">
                </button>
                <h2 id="gcp-about-title">GaiaCP</h2>
                <p class="gcp-about-version">
                    {{ __('Version :version', ['version' => Version::current()]) }}@if (Version::stageLabel()) · {{ Version::stageLabel() }}@endif
                </p>

                <p class="gcp-about-what">{{ __('Billing and provisioning for hosting and domain sellers.') }}</p>

                <div class="gcp-about-stack">
                    @foreach (About::stack() as $name => $version)
                        <span>{{ $name }} {{ $version }}</span>
                    @endforeach
                </div>

                <div class="gcp-about-links">
                    <a href="{{ Help::getUrl() }}">{{ __('Help') }}</a>
                    @if (Updates::canAccess())
                        <a href="{{ Updates::getUrl() }}">{{ __('Updates') }}</a>
                    @endif
                    <a href="{{ Version::WEBSITE_URL }}" target="_blank" rel="noopener">{{ __('Website') }}</a>
                    <a href="{{ Version::REPOSITORY_URL }}" target="_blank" rel="noopener">GitHub</a>
                    <a href="{{ Version::ISSUES_URL }}" target="_blank" rel="noopener">{{ __('Report a problem') }}</a>
                </div>

                <p class="gcp-about-licence">{{ __('Free software under the GNU GPL, version 3 or later. Comes with no warranty.') }}</p>
            </div>
        </div>
    </template>

    @include('filament.about-family')
</div>
