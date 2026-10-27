{{-- App\Filament\Client\Pages\AnnouncementPage: one announcement's text. --}}
<x-filament-panels::page>
    <x-filament::section>
        <div class="gcp-help gcp-kb-article">{{ $announcement->html() }}</div>
    </x-filament::section>
</x-filament-panels::page>
