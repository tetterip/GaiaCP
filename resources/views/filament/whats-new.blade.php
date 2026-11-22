@php
    use App\Support\WhatsNew;

    $highlights = WhatsNew::highlights();
@endphp

{{--
    What's new (App\Support\WhatsNew): this version's highlights, once per admin after an update, and from the user
    menu → What's new. One card at a time when there are several.
--}}
<div
    x-data="{
        open: false,
        step: 0,
        last: {{ count($highlights) - 1 }},

        init() {
            if (this.$el.dataset.whatsNewNow === 'yes') {
                this.$nextTick(() => this.start())
            }
        },

        start() {
            this.step = 0
            this.open = true
        },
    }"
    data-whats-new-now="{{ $opensNow ? 'yes' : 'no' }}"
    x-on:open-whats-new.window="start()"
    x-on:keydown.escape.window="if (open) open = false"
>
    <style>
        .gcp-wn-backdrop { position: fixed; inset: 0; z-index: 60; background: rgb(0 0 0 / 0.5); }
        .gcp-wn-panel { position: fixed; z-index: 61; top: 50%; left: 50%; transform: translate(-50%, -50%); width: min(28rem, calc(100vw - 2rem)); max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 1rem; background: #fff; color: var(--gray-950); box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35); }
        .dark .gcp-wn-panel { background: var(--gray-900); color: #fff; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.6), 0 0 0 1px rgb(255 255 255 / 0.1); }
        .gcp-wn-close { position: absolute; top: 0.75rem; inset-inline-end: 0.75rem; z-index: 1; padding: 0.25rem; border-radius: 0.375rem; color: var(--gray-400); }
        .gcp-wn-close:hover { color: var(--gray-600); }
        .gcp-wn-body { padding: 1.25rem 1.5rem 1.5rem; }
        .gcp-wn-eyebrow { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--primary-600); }
        .dark .gcp-wn-eyebrow { color: var(--primary-400); }
        .gcp-wn-panel h2 { margin-top: 0.25rem; font-size: 1.125rem; font-weight: 600; }
        .gcp-wn-text { margin-top: 0.375rem; font-size: 0.875rem; line-height: 1.55; color: var(--gray-600); }
        .dark .gcp-wn-text { color: var(--gray-300); }
        .gcp-wn-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem; }
        .gcp-wn-dots { display: flex; margin-inline: -0.5rem auto; }
        .gcp-wn-dots button { display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; }
        .gcp-wn-dots button::before { content: ''; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--gray-300); }
        .dark .gcp-wn-dots button::before { background: var(--gray-600); }
        .gcp-wn-dots button.is-on::before { background: var(--primary-600); }
    </style>

    <template x-teleport="body">
        <div x-show="open" x-trap.noscroll="open" style="display: none" role="dialog" aria-modal="true" aria-labelledby="gcp-wn-title">
            <div class="gcp-wn-backdrop" x-on:click="open = false"></div>

            <div class="gcp-wn-panel">

                @foreach ($highlights as $i => $item)
                    <div x-show="step === {{ $i }}" @if ($i > 0) style="display: none" @endif>
                        @if ($item['image'])
                            @include($item['image'])
                        @endif

                        <div class="gcp-wn-body">
                            <p class="gcp-wn-eyebrow">{{ __('New in :version', ['version' => WhatsNew::version()]) }}</p>
                            <h2 @if ($i === 0) id="gcp-wn-title" @endif>{{ $item['title'] }}</h2>
                            <p class="gcp-wn-text">{{ $item['text'] }}</p>
                        </div>
                    </div>
                @endforeach

                <div class="gcp-wn-body" style="padding-top: 0">
                    <div class="gcp-wn-foot" style="margin-top: 0">
                        @if (count($highlights) > 1)
                            <div class="gcp-wn-dots">
                                @foreach ($highlights as $i => $item)
                                    <button type="button" x-on:click="step = {{ $i }}" x-bind:class="{ 'is-on': step === {{ $i }} }" x-bind:aria-current="step === {{ $i }} ? 'step' : 'false'" aria-label="{{ $item['title'] }}"></button>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($highlights as $i => $item)
                            @if ($item['url'])
                                <x-filament::button color="gray" size="sm" outlined tag="a" :href="$item['url']" x-show="step === {{ $i }}" x-on:click="open = false">
                                    {{ $item['link'] }}
                                </x-filament::button>
                            @endif
                        @endforeach

                        <x-filament::button size="sm" x-show="step < last" x-on:click="step++">
                            {{ __('Next') }}
                        </x-filament::button>
                        <x-filament::button size="sm" x-show="step === last" x-on:click="open = false">
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
