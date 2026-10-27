{{-- App\Filament\Client\Pages\KnowledgeBaseArticle: the text, "Was this helpful?" and others in its category. --}}
<x-filament-panels::page>
    <x-filament::section>
        @if ($article->category)
            <p class="gcp-kb-meta" style="margin-bottom: .75rem;">{{ $article->category->name() }}</p>
        @endif
        <div class="gcp-help gcp-kb-article">{{ $this->body() }}</div>
    </x-filament::section>

    <x-filament::section>
        <div class="gcp-kb-vote">
            @if ($voted === null)
                <span>{{ __('Was this helpful?') }}</span>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-hand-thumb-up" wire:click="vote(true)">{{ __('Yes') }}</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-hand-thumb-down" wire:click="vote(false)">{{ __('No') }}</x-filament::button>
            @else
                <span>{{ __('Thank you for your answer.') }}</span>
            @endif
            @if ($ticket = $this->ticketUrl())
                <span class="gcp-kb-meta">{{ __('Still need help?') }} <a href="{{ $ticket }}" class="gcp-kb-inline">{{ __('Open a ticket') }}</a></span>
            @endif
        </div>
    </x-filament::section>

    @php($related = $this->related())
    @if ($related->isNotEmpty())
        <x-filament::section :heading="__('More in :category', ['category' => $article->category->name()])">
            @foreach ($related as $other)
                <a class="gcp-kb-link" href="{{ $this->articleUrl($other) }}" wire:navigate>{{ $other->title() }}</a>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
