{{-- App\Filament\Client\Pages\Downloads: the files by category, each with its description, size and a Download button (styled by filament.themes.client). --}}
<x-filament-panels::page>
    <div class="gcp-kb-groups">
        @foreach ($this->groups() as $group)
            <x-filament::section :heading="$group['name']">
                <div class="gcp-downloads">
                    @foreach ($group['downloads'] as $download)
                        <div class="gcp-download">
                            <div class="gcp-download-text">
                                <span class="gcp-download-title">{{ $download->title() }}</span>
                                @if ($description = $download->description())
                                    <span class="gcp-kb-meta">{{ $description }}</span>
                                @endif
                                <span class="gcp-kb-meta">{{ $download->file_name }} · {{ $download->humanSize() }}</span>
                            </div>
                            <x-filament::button tag="a" :href="$this->fileUrl($download)" size="sm" color="gray" icon="heroicon-o-arrow-down-tray">{{ __('Download') }}</x-filament::button>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
