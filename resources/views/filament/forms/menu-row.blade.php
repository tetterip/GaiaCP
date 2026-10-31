{{-- A line of Settings → Client area → Menus (MenuRowBlock): its icon and what it opens --}}
<div style="display: flex; align-items: center; gap: .5rem; padding: .5rem 1rem; font-size: .875rem; opacity: .7; min-width: 0">
    @if ($icon)
        <x-filament::icon :icon="$icon" style="width: 1.25rem; height: 1.25rem; flex-shrink: 0" />
    @endif
    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $text }}</span>
</div>
