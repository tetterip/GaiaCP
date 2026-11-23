@php
    use App\Support\WhatsNew;

    $news = WhatsNew::current();
@endphp

{{--
    What's new (App\Support\WhatsNew): once per admin after an update, and from the user menu → What's new. The picture
    is made from the version's features (an icon and a few words each), so a release needs no artwork, and it follows
    the panel's colours, dark mode and language.
--}}
<div
    x-data="{
        open: false,

        init() {
            if (this.$el.dataset.whatsNewNow === 'yes') {
                this.$nextTick(() => this.open = true)
            }
        },
    }"
    data-whats-new-now="{{ $opensNow ? 'yes' : 'no' }}"
    x-on:open-whats-new.window="open = true"
    x-on:keydown.escape.window="if (open) open = false"
>
    <style>
        .gcp-wn-backdrop { position: fixed; inset: 0; z-index: 60; background: rgb(0 0 0 / 0.5); }
        .gcp-wn-panel { position: fixed; z-index: 61; top: 50%; left: 50%; transform: translate(-50%, -50%); width: min(28rem, calc(100vw - 2rem)); max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 1rem; background: #fff; color: var(--gray-950); box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35); }
        .dark .gcp-wn-panel { background: var(--gray-900); color: #fff; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.6), 0 0 0 1px rgb(255 255 255 / 0.1); }
        .gcp-wn-close { position: absolute; top: 0.75rem; inset-inline-end: 0.75rem; padding: 0.25rem; border-radius: 0.375rem; color: rgb(255 255 255 / 0.75); }
        .gcp-wn-close:hover { color: #fff; }

        /* The picture: the version and its features, on the panel's own colour */
        .gcp-wn-hero { position: relative; overflow: hidden; padding: 1.5rem 1.5rem 1.25rem; color: #fff; background: radial-gradient(120% 90% at 100% 0%, var(--primary-400), transparent 60%), linear-gradient(150deg, var(--primary-700), var(--primary-950, #0f2b24)); }
        .gcp-wn-hero::after { content: ''; position: absolute; inset-inline-end: -3rem; bottom: -4rem; width: 11rem; height: 11rem; border-radius: 9999px; border: 1.5rem solid rgb(255 255 255 / 0.06); pointer-events: none; }
        .gcp-wn-brand { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; font-weight: 600; opacity: 0.85; }
        .gcp-wn-brand img { width: 1.25rem; height: 1.25rem; }
        .gcp-wn-version { margin-top: 0.25rem; font-size: 2.25rem; font-weight: 700; line-height: 1.1; letter-spacing: -0.02em; }
        .gcp-wn-features { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; margin-top: 1rem; }
        .gcp-wn-feature { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.625rem; border-radius: 0.625rem; background: rgb(255 255 255 / 0.12); box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.14); font-size: 0.8125rem; font-weight: 600; line-height: 1.25; }
        .gcp-wn-feature span:first-child { display: flex; flex: none; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; background: #fff; color: var(--primary-700); }

        .gcp-wn-body { padding: 1.25rem 1.5rem 1.5rem; }
        .gcp-wn-eyebrow { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--primary-600); }
        .dark .gcp-wn-eyebrow { color: var(--primary-400); }
        .gcp-wn-text { margin-top: 0.375rem; font-size: 0.9375rem; line-height: 1.55; color: var(--gray-700); }
        .dark .gcp-wn-text { color: var(--gray-200); }
        .gcp-wn-foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem; }
    </style>

    <template x-teleport="body">
        <div x-show="open" x-trap.noscroll="open" style="display: none" role="dialog" aria-modal="true" aria-labelledby="gcp-wn-title">
            <div class="gcp-wn-backdrop" x-on:click="open = false"></div>

            <div class="gcp-wn-panel">
                <div class="gcp-wn-hero">
                    <p class="gcp-wn-brand"><img src="{{ asset('images/gaiacp/mark.svg') }}" alt="">GaiaCP</p>
                    <p class="gcp-wn-version" id="gcp-wn-title">{{ WhatsNew::version() }}</p>
                    <ul class="gcp-wn-features">
                        @foreach ($news['features'] as $feature)
                            <li class="gcp-wn-feature">
                                <span><x-filament::icon :icon="$feature['icon']" class="h-4 w-4" /></span>
                                <span>{{ $feature['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="gcp-wn-body">
                    <p class="gcp-wn-eyebrow">{{ __("What's new") }}</p>
                    <p class="gcp-wn-text">{{ $news['summary'] }}</p>

                    <div class="gcp-wn-foot">
                        <x-filament::button color="gray" size="sm" outlined tag="a" :href="WhatsNew::notesUrl()" target="_blank" rel="noopener">
                            {{ __('Release notes') }}
                        </x-filament::button>
                        <x-filament::button size="sm" x-on:click="open = false">
                            {{ __('OK') }}
                        </x-filament::button>
                    </div>
                </div>

                {{-- Last, so the keyboard focus starts on the buttons --}}
                <button type="button" class="gcp-wn-close" x-on:click="open = false" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
            </div>
        </div>
    </template>
</div>
