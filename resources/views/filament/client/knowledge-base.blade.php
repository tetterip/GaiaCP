{{-- App\Filament\Client\Pages\KnowledgeBase: a search, then the articles by category (styled by filament.themes.client). --}}
<x-filament-panels::page>
    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
        <x-filament::input type="search" wire:model.live.debounce.400ms="search" :placeholder="__('Search the articles')" />
    </x-filament::input.wrapper>

    @if (filled(trim($search)))
        <x-filament::section>
            @php($results = $this->results())
            @forelse ($results as $article)
                <a class="gcp-kb-link" href="{{ $this->articleUrl($article) }}" wire:navigate>
                    <span>{{ $article->title() }}</span>
                    @if ($article->category)
                        <span class="gcp-kb-meta">{{ $article->category->name() }}</span>
                    @endif
                </a>
            @empty
                <p class="gcp-kb-meta">{{ __('No article matches “:search”.', ['search' => $search]) }}</p>
            @endforelse
        </x-filament::section>
    @else
        <div class="gcp-kb-groups">
            @foreach ($this->groups() as $group)
                <x-filament::section :heading="$group['name']">
                    @foreach ($group['articles'] as $article)
                        <a class="gcp-kb-link" href="{{ $this->articleUrl($article) }}" wire:navigate>{{ $article->title() }}</a>
                    @endforeach
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
