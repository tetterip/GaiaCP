@php
    use App\Support\WelcomeTour;

    $steps = WelcomeTour::steps();
    $finish = WelcomeTour::finish();
    $last = count($steps) - 1;
@endphp

{{-- The welcome tour (App\Support\WelcomeTour): opens by itself on the first visit, and from the user menu → Tour. --}}
<div
    x-data="{ open: @js($opensNow), step: 0, last: {{ $last }} }"
    x-on:open-welcome-tour.window="step = 0; open = true"
    x-on:keydown.escape.window="open = false"
    x-on:keydown.arrow-right.window="if (open && step < last) step++"
    x-on:keydown.arrow-left.window="if (open && step > 0) step--"
>
    <style>
        .gcp-tour-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgb(0 0 0 / 0.5); }
        .gcp-tour-panel { position: relative; width: 100%; max-width: 28rem; max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 0.75rem; padding: 1.5rem; background: #fff; color: var(--gray-950); box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.2); }
        .dark .gcp-tour-panel { background: var(--gray-900); color: #fff; }
        .gcp-tour-close { position: absolute; top: 0.75rem; inset-inline-end: 0.75rem; padding: 0.25rem; border-radius: 0.375rem; color: var(--gray-400); }
        .gcp-tour-close:hover { color: var(--gray-600); }
        .gcp-tour-icon { display: flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 9999px; margin-bottom: 1rem; background: var(--primary-50); color: var(--primary-600); }
        .dark .gcp-tour-icon { background: rgb(255 255 255 / 0.05); color: var(--primary-400); }
        .gcp-tour-panel h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; padding-inline-end: 1.5rem; }
        .gcp-tour-text { font-size: 0.875rem; line-height: 1.5; color: var(--gray-600); }
        .dark .gcp-tour-text { color: var(--gray-300); }
        .gcp-tour-link { display: inline-block; margin-top: 0.75rem; font-size: 0.875rem; font-weight: 600; color: var(--primary-600); }
        .dark .gcp-tour-link { color: var(--primary-400); }
        .gcp-tour-link:hover { text-decoration: underline; }
        .gcp-tour-foot { display: flex; align-items: center; gap: 0.5rem; margin-top: 1.5rem; }
        .gcp-tour-dots { display: flex; gap: 0.375rem; margin-inline-end: auto; }
        .gcp-tour-dots button { width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--gray-300); }
        .dark .gcp-tour-dots button { background: var(--gray-600); }
        .gcp-tour-dots button.is-on { background: var(--primary-600); }
    </style>

    <template x-teleport="body">
        <div
            x-show="open"
            x-transition.opacity
            x-on:click.self="open = false"
            class="gcp-tour-backdrop"
            style="display: none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="gcp-tour-title"
        >
            <div class="gcp-tour-panel">
                <button type="button" class="gcp-tour-close" x-on:click="open = false" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>

                @foreach ($steps as $i => $item)
                    <div x-show="step === {{ $i }}" @if ($i > 0) style="display: none" @endif>
                        <div class="gcp-tour-icon">
                            <x-filament::icon :icon="$item['icon']" class="h-6 w-6" />
                        </div>
                        <h2 @if ($i === 0) id="gcp-tour-title" @endif>{{ $item['title'] }}</h2>
                        <p class="gcp-tour-text">{{ $item['text'] }}</p>
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="gcp-tour-link" x-on:click="open = false">{{ $item['link'] }} →</a>
                        @endif
                    </div>
                @endforeach

                <div class="gcp-tour-foot">
                    <div class="gcp-tour-dots">
                        @foreach ($steps as $i => $item)
                            <button type="button" x-on:click="step = {{ $i }}" x-bind:class="{ 'is-on': step === {{ $i }} }" aria-label="{{ $item['title'] }}"></button>
                        @endforeach
                    </div>

                    <x-filament::button color="gray" size="sm" outlined x-show="step > 0" x-on:click="step--">
                        {{ __('Back') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" outlined x-show="step === 0" x-on:click="open = false">
                        {{ __('Skip') }}
                    </x-filament::button>
                    <x-filament::button size="sm" x-show="step < last" x-on:click="step++">
                        {{ __('Next') }}
                    </x-filament::button>
                    <div x-show="step === last" style="display: none">
                        @if ($finish['url'])
                            <x-filament::button size="sm" tag="a" :href="$finish['url']">{{ $finish['label'] }}</x-filament::button>
                        @else
                            <x-filament::button size="sm" x-on:click="open = false">{{ $finish['label'] }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
