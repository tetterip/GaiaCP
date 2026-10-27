<div>
    {{-- No custom theme is compiled, so the cards carry their own CSS on Filament's color variables. --}}
    <style>
        .gcp-u { display: grid; gap: 1rem; }
        .gcp-u-main { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }
        .gcp-u-card { display: flex; align-items: center; gap: 1rem; padding: 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .gcp-u-ring { position: relative; flex: none; width: 4.5rem; height: 4.5rem; }
        .gcp-u-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .gcp-u-ring circle { fill: none; stroke-width: 7; }
        .gcp-u-ring .track { stroke: var(--gray-100); }
        .gcp-u-ring .fill { stroke-linecap: round; stroke-dasharray: 100; animation: gcp-u-ring .9s cubic-bezier(.22, 1, .36, 1) both; }
        .gcp-u-ring span { position: absolute; inset: 0; display: grid; place-items: center; font-size: .875rem; font-weight: 600; color: var(--gray-950); font-variant-numeric: tabular-nums; }
        .gcp-u-label { display: flex; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--gray-500); }
        .gcp-u-label svg { width: 1rem; height: 1rem; }
        .gcp-u-value { margin-top: .125rem; font-size: 1.5rem; line-height: 2rem; font-weight: 600; color: var(--gray-950); font-variant-numeric: tabular-nums; }
        .gcp-u-limit { font-size: .8125rem; color: var(--gray-500); }
        .gcp-u-tiles { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (min-width: 640px) { .gcp-u-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .gcp-u-tile { padding: .75rem .875rem; border-radius: .75rem; background: var(--gray-50); }
        .gcp-u-tile .gcp-u-value { font-size: 1.125rem; line-height: 1.75rem; }
        .gcp-u-tile .gcp-u-value { white-space: nowrap; }
        .gcp-u-tile .gcp-u-limit { margin-left: .375rem; white-space: nowrap; }
        .gcp-u-bar { margin-top: .5rem; height: .25rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden; }
        .gcp-u-bar div { height: 100%; border-radius: 9999px; animation: gcp-u-bar .9s cubic-bezier(.22, 1, .36, 1) both; transform-origin: left; }
        .gcp-u-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; font-size: .75rem; color: var(--gray-500); }
        .gcp-u-error { font-size: .875rem; color: var(--gray-600); }
        @keyframes gcp-u-ring { from { stroke-dashoffset: 100; } }
        @keyframes gcp-u-bar { from { transform: scaleX(0); } }
        @media (prefers-reduced-motion: reduce) { .gcp-u-ring .fill, .gcp-u-bar div { animation: none; } }
        .dark .gcp-u-card { border-color: rgb(255 255 255 / .1); }
        .dark .gcp-u-ring .track, .dark .gcp-u-bar { stroke: rgb(255 255 255 / .1); background: rgb(255 255 255 / .1); }
        .dark .gcp-u-tile { background: rgb(255 255 255 / .05); }
        .dark .gcp-u-value, .dark .gcp-u-ring span { color: #fff; }
        .dark .gcp-u-label, .dark .gcp-u-limit, .dark .gcp-u-foot { color: var(--gray-400); }
        .dark .gcp-u-error { color: var(--gray-400); }
    </style>

    <div class="gcp-u">
        @if ($error)
            <div class="gcp-u-error">{{ $error }}</div>
        @endif

        @if ($main)
            <div class="gcp-u-main">
                @foreach ($main as $item)
                    <div class="gcp-u-card">
                        <div class="gcp-u-ring" role="img" aria-label="{{ $item['label'] }}: {{ $item['value'] }}, {{ $item['limit'] }}">
                            <svg viewBox="0 0 80 80" aria-hidden="true">
                                <circle class="track" cx="40" cy="40" r="34" />
                                @if ($item['percent'] !== null)
                                    <circle class="fill" cx="40" cy="40" r="34" pathLength="100"
                                        style="stroke: var(--{{ $item['color'] }}-500); stroke-dashoffset: {{ 100 - max(min($item['percent'], 100), 1) }}" />
                                @endif
                            </svg>
                            <span>{{ $item['percent'] === null ? '∞' : $item['percent'].'%' }}</span>
                        </div>
                        <div>
                            <div class="gcp-u-label">@svg($item['icon']) {{ $item['label'] }}</div>
                            <div class="gcp-u-value">{{ $item['value'] }}</div>
                            <div class="gcp-u-limit">{{ $item['limit'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($tiles)
            <div class="gcp-u-tiles">
                @foreach ($tiles as $item)
                    <div class="gcp-u-tile">
                        <div class="gcp-u-label">@svg($item['icon']) {{ $item['label'] }}</div>
                        <div><span class="gcp-u-value">{{ $item['value'] }}</span><span class="gcp-u-limit">{{ $item['limit'] }}</span></div>
                        @if ($item['percent'] !== null)
                            <div class="gcp-u-bar"><div style="width: {{ max(min($item['percent'], 100), 2) }}%; background: var(--{{ $item['color'] }}-500)"></div></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($usage?->readAt || ! $client)
            <div class="gcp-u-foot">
                <span>
                    @if ($usage?->readAt)
                        {{ __('Read from the server :time.', ['time' => $usage->readAt->diffForHumans()]) }}
                    @endif
                </span>
                @unless ($client)
                    <x-filament::link tag="button" size="sm" icon="heroicon-m-arrow-path" wire:click="refreshUsage" wire:loading.attr="disabled">
                        {{ __('Read again') }}
                    </x-filament::link>
                @endunless
            </div>
        @endif
    </div>
</div>
