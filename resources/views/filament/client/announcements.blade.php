{{-- App\Filament\Client\Pages\Announcements: the published announcements, newest first, a page at a time. --}}
<x-filament-panels::page>
    @php($announcements = $this->announcements())
    <div class="gcp-news-list">
        @foreach ($announcements as $announcement)
            <x-filament::section>
                <a class="gcp-news-title" href="{{ $this->announcementUrl($announcement) }}" wire:navigate>{{ $announcement->title() }}</a>
                <p class="gcp-kb-meta">{{ $announcement->published_at->timezone(config('app.timezone'))->format(\App\Providers\AppServiceProvider::dateFormat()) }}</p>
                <p class="gcp-news-excerpt">{{ $announcement->excerpt() }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <div class="gcp-news-foot">
        <x-filament::pagination :paginator="$announcements" />
        @if ($feed = $this->feedUrl())
            <a class="gcp-kb-inline gcp-news-feed" href="{{ $feed }}">
                <x-filament::icon icon="heroicon-o-rss" class="gcp-news-feed-icon" />{{ __('RSS feed') }}
            </a>
        @endif
    </div>
</x-filament-panels::page>
