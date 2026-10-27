<x-filament-panels::page>
    {{-- No custom theme is compiled, so the steps carry their own CSS on Filament's color variables. --}}
    <style>
        .gcp-setup { display: grid; gap: .75rem; max-width: 52rem; }
        .gcp-setup-bar { height: .4rem; border-radius: 999px; background: var(--gray-200); overflow: hidden; }
        .gcp-setup-bar span { display: block; height: 100%; background: var(--primary-500); border-radius: inherit; transition: width .3s ease; }
        .dark .gcp-setup-bar { background: rgb(255 255 255 / .1); }
        .gcp-step { display: flex; gap: .875rem; align-items: flex-start; padding: 1rem 1.1rem; border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); }
        .dark .gcp-step { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .gcp-step-mark { flex: none; display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: 999px; font-size: .8125rem; font-weight: 600; background: var(--primary-50); color: var(--primary-700); }
        .gcp-step-mark svg { width: 1.1rem; height: 1.1rem; }
        .dark .gcp-step-mark { background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-300); }
        .gcp-step-done .gcp-step-mark { background: var(--success-500); color: #fff; }
        .gcp-step-skipped .gcp-step-mark { background: var(--gray-100); color: var(--gray-500); }
        .dark .gcp-step-skipped .gcp-step-mark { background: rgb(255 255 255 / .08); color: var(--gray-400); }
        .gcp-step-text { flex: 1; min-width: 0; }
        .gcp-step-title { font-weight: 600; color: var(--gray-950); }
        .dark .gcp-step-title { color: #fff; }
        .gcp-step-done .gcp-step-title, .gcp-step-skipped .gcp-step-title { color: var(--gray-500); }
        .gcp-step-desc { margin-top: .15rem; font-size: .8125rem; line-height: 1.45; color: var(--gray-500); }
        .dark .gcp-step-desc { color: var(--gray-400); }
        .gcp-step-state { margin-left: .4rem; font-size: .75rem; font-weight: 500; color: var(--gray-500); }
        .gcp-step-actions { display: flex; flex-wrap: wrap; gap: .4rem .9rem; align-items: center; margin-top: .6rem; font-size: .8125rem; }
        .gcp-step-actions a, .gcp-step-actions button { color: var(--primary-600); font-weight: 500; background: none; border: 0; padding: 0; cursor: pointer; text-decoration: none; }
        .gcp-step-actions a:hover, .gcp-step-actions button:hover { text-decoration: underline; }
        .gcp-step-actions .gcp-step-quiet { color: var(--gray-500); }
        .dark .gcp-step-actions a, .dark .gcp-step-actions button { color: var(--primary-400); }
        .dark .gcp-step-actions .gcp-step-quiet { color: var(--gray-400); }
    </style>

    @php($steps = \App\Support\SetupGuide::steps())
    @php($done = $steps->whereNotNull('status')->count())
    <div class="gcp-setup">
        <div class="gcp-setup-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $steps->count() }}" aria-valuenow="{{ $done }}">
            <span style="width: {{ $steps->count() ? round($done / $steps->count() * 100) : 0 }}%"></span>
        </div>

        @foreach ($steps as $i => $step)
            <div @class(['gcp-step', 'gcp-step-done' => $step['status'] === 'done', 'gcp-step-skipped' => $step['status'] === 'skipped']) wire:key="step-{{ $step['key'] }}">
                <span class="gcp-step-mark">
                    @if ($step['status'] === 'done')
                        <x-filament::icon icon="heroicon-m-check" />
                    @elseif ($step['status'] === 'skipped')
                        <x-filament::icon icon="heroicon-m-minus" />
                    @else
                        {{ $i + 1 }}
                    @endif
                </span>
                <div class="gcp-step-text">
                    <div class="gcp-step-title">
                        {{ $step['title'] }}
                        @if ($step['status'] === 'skipped')<span class="gcp-step-state">{{ __('Skipped') }}</span>@endif
                    </div>
                    <div class="gcp-step-desc">{{ $step['description'] }}</div>
                    <div class="gcp-step-actions">
                        <a href="{{ $step['url'] }}">{{ $step['status'] === 'done' ? __('Change') : __('Open') }}</a>
                        @if ($step['status'] === null)
                            <button type="button" wire:click="mark('{{ $step['key'] }}', 'done')">{{ __('Mark as done') }}</button>
                            <button type="button" class="gcp-step-quiet" wire:click="mark('{{ $step['key'] }}', 'skipped')">{{ __('Skip') }}</button>
                        @elseif (! $step['auto'])
                            <button type="button" class="gcp-step-quiet" wire:click="mark('{{ $step['key'] }}', null)">{{ __('Undo') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
