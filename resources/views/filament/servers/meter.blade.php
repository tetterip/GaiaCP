@php
    /**
     * A row of squares under a caption and a value: the uptime bar and the accounts bar look the same everywhere.
     *
     * @var ?string $caption what the squares show (null where a column header already says it)
     * @var string $value the number next to the caption
     * @var string $label the squares' accessible name
     * @var list<array{color: ?string, title?: ?string}> $cells one square each, oldest / first on the left; a null colour is empty
     * @var int $rows lines the squares wrap onto (2 in a narrow table column), read left to right like a calendar
     * @var bool $fill the squares grow to the full width (dashboard, client hosting page) instead of staying 12px
     */
    $columns = (int) ceil(max(1, count($cells)) / max(1, $rows ?? 1));
    $tracks = "repeat({$columns}, minmax(0, 1fr))";
    $fill ??= false;
    if ($fill) {
        // Whole pixels, so no square comes out 1px wider than its neighbours; the caption row takes the same width
        // as the squares (subgrid). Browsers without round() keep the fractional widths above.
        $whole = 'repeat('.$columns.', round(down, calc((100% - '.(($columns - 1) * 2).'px) / '.$columns.'), 1px))';
        $tracks = "{$tracks}; grid-template-columns: {$whole}";
    }
@endphp
{{-- Inline styles in Filament's colour variables: the list and the dashboard share it and there is no compiled theme. --}}
<div class="gx-meter" style="display: grid; gap: .3rem; min-width: 0; {{ $fill ? 'column-gap: 2px; grid-template-columns: '.$tracks.';' : 'max-width: '.($columns * 14).'px;' }}">
    <div style="grid-column: 1 / -1; display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; font-size: .75rem; line-height: 1rem;">
        @if ($caption)
            <span style="opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $caption }}</span>
        @endif
        <span style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $value }}</span>
    </div>
    <div role="img" aria-label="{{ $label }}" style="grid-column: 1 / -1; display: grid; grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); {{ $fill ? 'grid-template-columns: subgrid;' : '' }} gap: 2px;">
        @foreach ($cells as $cell)
            <span @if ($cell['title'] ?? null) title="{{ $cell['title'] }}" @endif @if ($cell['color'] ?? null) data-on @endif
                style="--gx-i: {{ $loop->index }}; aspect-ratio: 1; border-radius: 2px; background: {{ $cell['color'] ?? 'rgb(127 127 127 / .2)' }};"></span>
        @endforeach
    </div>
</div>
