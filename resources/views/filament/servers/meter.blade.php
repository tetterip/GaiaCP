@php
    /**
     * A row of squares under a caption and a value: the uptime bar and the accounts bar look the same everywhere.
     *
     * @var ?string $caption what the squares show (null where a column header already says it)
     * @var string $value the number next to the caption
     * @var string $label the squares' accessible name
     * @var list<array{color: ?string, title?: ?string, shape?: string}> $cells one square each, oldest / first on the left; a null
     *                  colour is empty; a shape (up, warn, down, none: the uptime bar) draws that shape in the square instead
     * @var int $rows lines the squares wrap onto (2 in a narrow table column), read left to right like a calendar
     * @var bool $fill the squares grow to the full width (dashboard, client hosting page) instead of staying
     *                  10px, so bars side by side in the servers list have squares of one size
     */
    $columns = (int) ceil(max(1, count($cells)) / max(1, $rows ?? 1));
    $tracks = "repeat({$columns}, minmax(0, 1fr))";
    $fill ??= false;
    $square = 10;
    if ($fill) {
        // Whole pixels, so no square comes out 1px wider than its neighbours; the caption row takes the same width
        // as the squares (subgrid). The pixels left over by rounding down are spread over the gaps (space-between), so the
        // row still reaches the right edge, under its value. Browsers without round() keep the fractional widths above.
        $whole = 'repeat('.$columns.', round(down, calc((100% - '.(($columns - 1) * 2).'px) / '.$columns.'), 1px))';
        $tracks = "{$tracks}; grid-template-columns: {$whole}";
    }
@endphp
{{-- Inline styles in Filament's colour variables: the list and the dashboard share it and there is no compiled theme. --}}
<div class="gx-meter" style="display: grid; gap: .3rem; min-width: 0; {{ $fill ? 'column-gap: 2px; justify-content: space-between; grid-template-columns: '.$tracks.';' : 'width: max-content; max-width: 100%;' }}">
    <div style="grid-column: 1 / -1; display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; font-size: .75rem; line-height: 1rem;">
        @if ($caption)
            <span style="opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $caption }}</span>
        @endif
        <span style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $value }}</span>
    </div>
    <div role="img" aria-label="{{ $label }}" style="grid-column: 1 / -1; display: grid; grid-template-columns: {{ $fill ? 'subgrid' : 'repeat('.$columns.', '.$square.'px)' }}; gap: 2px;">
        @foreach ($cells as $cell)
            @if ($cell['shape'] ?? null)
                <span @if ($cell['title'] ?? null) title="{{ $cell['title'] }}" @endif @if ($cell['color'] ?? null) data-on @endif data-shape="{{ $cell['shape'] }}"
                    style="--gx-i: {{ $loop->index }}; aspect-ratio: 1; display: grid; place-items: center;"><svg viewBox="0 0 20 20" aria-hidden="true" style="width: 80%; height: 80%; overflow: visible; fill: {{ $cell['color'] ?? 'rgb(127 127 127 / .35)' }}; stroke: {{ $cell['color'] ?? 'none' }};">@switch($cell['shape'])
                    @case('down')<path d="M2.5 4h15l-7.5 12.5z" stroke-width="3.5" stroke-linejoin="round"/>@break
                    @case('warn')<path d="M2.5 16h15l-7.5-12.5z" stroke-width="3.5" stroke-linejoin="round"/>@break
                    @case('up')<circle cx="10" cy="10" r="7.75" stroke="none"/>@break
                    @default<circle cx="10" cy="10" r="3.75" stroke="none"/>
                @endswitch</svg></span>
            @else
                <span @if ($cell['title'] ?? null) title="{{ $cell['title'] }}" @endif @if ($cell['color'] ?? null) data-on @endif
                    style="--gx-i: {{ $loop->index }}; aspect-ratio: 1; border-radius: 2px; background: {{ $cell['color'] ?? 'rgb(127 127 127 / .2)' }};"></span>
            @endif
        @endforeach
    </div>
</div>
