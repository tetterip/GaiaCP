{{-- A small badge under a cell of the servers list: the monitor's status under the name, the panel type under the hostname. --}}
<span class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1">
    <x-filament::badge :color="$color" size="sm" :tooltip="$tooltip ?? null" style="min-width: max-content">{{ $label }}</x-filament::badge>
    @if (filled($after ?? null))
        <span>{{ $after }}</span>
    @endif
</span>
