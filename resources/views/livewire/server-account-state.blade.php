<div>
    {{-- No custom theme is compiled, so the box carries its own CSS on Filament's color variables. --}}
    <style>
        .gcp-s-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
        .gcp-s-badge { display: inline-flex; align-items: center; gap: .375rem; padding: .125rem .625rem; border-radius: 9999px; font-size: .8125rem; font-weight: 600; }
        .gcp-s-badge span { width: .5rem; height: .5rem; border-radius: 9999px; background: currentColor; }
        .gcp-s-note { font-size: .875rem; color: var(--gray-500); overflow-wrap: anywhere; }
        .gcp-s-again { margin-inline-start: auto; }
        .gcp-s-problem { margin-top: .75rem; padding: .75rem .875rem; border-radius: .5rem; border: 1px solid var(--danger-200); border-inline-start: 4px solid var(--danger-600); background: var(--danger-50); font-size: .875rem; line-height: 1.45; color: var(--danger-900); }
        .gcp-s-problem strong { color: var(--danger-700); }
        .gcp-s-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .625rem; }
        .dark .gcp-s-note { color: var(--gray-400); }
        .dark .gcp-s-problem { border-color: rgb(248 113 113 / .3); border-inline-start-color: var(--danger-500); background: rgb(248 113 113 / .1); color: var(--danger-200); }
        .dark .gcp-s-problem strong { color: var(--danger-300); }
    </style>

    <div class="gcp-s-row">
        <span class="gcp-s-badge" style="color: var(--{{ $badge['color'] }}-700); background: color-mix(in srgb, var(--{{ $badge['color'] }}-500) 15%, transparent)">
            <span></span>{{ $badge['label'] }}
        </span>
        @if ($note)
            <span class="gcp-s-note">{{ $note }}</span>
        @endif
        <span class="gcp-s-again">
            <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-path" wire:click="checkAgain" wire:loading.attr="disabled" wire:target="checkAgain">
                {{ __('Check again') }}
            </x-filament::button>
        </span>
    </div>

    @if ($problem)
        <div class="gcp-s-problem" role="alert">
            <strong>{{ $problem['title'] }}</strong> {{ $problem['text'] }}
            <div class="gcp-s-actions">
                @foreach ($problem['actions'] as $action)
                    <x-filament::button size="sm" :color="$action['primary'] ? 'danger' : 'gray'" wire:click="{{ $action['method'] }}" wire:loading.attr="disabled"
                        :wire:confirm="$action['confirm']">
                        {{ $action['label'] }}
                    </x-filament::button>
                @endforeach
            </div>
        </div>
    @endif
</div>
