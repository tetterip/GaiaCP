{{-- App\Filament\Client\Pages\CustomPageView: a page of your own, as written with the editor (sanitized). --}}
<x-filament-panels::page>
    <x-filament::section>
        <div class="fi-prose gcp-custom-page">{{ $page->html() }}</div>
    </x-filament::section>
</x-filament-panels::page>
