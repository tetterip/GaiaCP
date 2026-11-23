{{-- The day's numbers over the dashboard's cards (App\Support\DashboardToday): four boxes in a row, two by two on a phone. --}}
@if (count($boxes))
    <div class="gx-today" aria-label="{{ __('Today') }}">
        @foreach ($boxes as $box)
            <a {{ \Filament\Support\generate_href_html($box['url']) }} class="fi-section gx-today-box" data-gx-today="{{ $box['key'] }}">
                <span class="gx-today-label">@svg($box['icon'], 'gx-today-ico'){{ $box['label'] }}</span>
                <span class="gx-today-value gx-tnum">{{ $box['value'] }}</span>
                <span class="gx-today-sub">{{ $box['sub'] }}</span>
            </a>
        @endforeach
    </div>
@endif
