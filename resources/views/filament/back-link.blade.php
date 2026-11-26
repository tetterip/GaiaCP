@php($back = \App\Support\BackLink::for(\Livewire\Livewire::current()))
@if ($back)
    <a {{ \Filament\Support\generate_href_html($back['url']) }} class="fi-back-link">
        <span aria-hidden="true">‹</span> {{ $back['label'] }}
    </a>
@endif
