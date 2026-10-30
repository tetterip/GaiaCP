<x-filament-panels::page>
    {{-- No custom theme is compiled, so the page carries its own CSS on Filament's color variables. --}}
    <style>
        .gcp-guide { display: grid; gap: 1.5rem; align-items: start; }
        @media (min-width: 64rem) { .gcp-guide { grid-template-columns: 15rem minmax(0, 1fr); } }
        .gcp-guide > * { min-width: 0; }
        .gcp-guide-nav { display: grid; gap: .75rem; }
        @media (min-width: 64rem) { .gcp-guide-nav { position: sticky; top: 5rem; } }
        .gcp-guide-nav ol { display: grid; gap: .125rem; font-size: .875rem; }
        .gcp-guide-nav a { display: block; padding: .375rem .625rem; border-radius: .5rem; color: var(--gray-700); }
        .dark .gcp-guide-nav a { color: var(--gray-300); }
        .gcp-guide-nav a:hover { background: var(--gray-100); }
        .dark .gcp-guide-nav a:hover { background: rgb(255 255 255 / .05); }
        .gcp-guide-nav a[aria-current] { background: var(--primary-50); color: var(--primary-700); font-weight: 600; }
        .dark .gcp-guide-nav a[aria-current] { background: rgb(255 255 255 / .06); color: var(--primary-400); }
        .gcp-guide-body { max-width: 52rem; }
        .gcp-guide-body h2, .gcp-guide-body h3 { scroll-margin-top: 5rem; }
        /* A long command (the cron line) scrolls inside its box instead of running past it on a phone */
        .gcp-guide-body pre { max-width: 100%; overflow-x: auto; }
        .gcp-guide-body :not(pre) > code { padding: .1rem .3rem; border-radius: .25rem; background: var(--gray-100); font-size: .85em; font-weight: 500; }
        .dark .gcp-guide-body :not(pre) > code { background: rgb(255 255 255 / .08); }
        .gcp-guide-body :not(pre) > code::before, .gcp-guide-body :not(pre) > code::after { content: none; }
        .gcp-guide-results { display: grid; gap: .5rem; }
        .gcp-guide-ask { display: grid; gap: .5rem; justify-items: start; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--gray-200); }
        .dark .gcp-guide-ask { border-color: rgb(255 255 255 / .1); }
        .gcp-guide-ask-note { font-size: .8125rem; color: var(--gray-500); }
        .gcp-guide-results a { display: block; padding: .75rem; border-radius: .5rem; }
        .gcp-guide-results a:hover { background: var(--gray-50); }
        .dark .gcp-guide-results a:hover { background: rgb(255 255 255 / .04); }
        .gcp-guide-results strong { display: block; font-size: .875rem; color: var(--gray-950); }
        .dark .gcp-guide-results strong { color: #fff; }
        .gcp-guide-results span { display: block; margin-top: .125rem; font-size: .8125rem; color: var(--gray-500); }
        /* On a phone the chapters are one row that scrolls sideways, so the text starts on the first screen */
        @media (max-width: 63.99rem) {
            .gcp-guide-nav ol { display: flex; gap: .25rem; overflow-x: auto; white-space: nowrap; scrollbar-width: none; }
            .gcp-guide-nav a { border: 1px solid var(--gray-200); }
            .dark .gcp-guide-nav a { border-color: rgb(255 255 255 / .1); }
        }
    </style>

    @php($current = $this->currentSlug())
    <div class="gcp-guide">
        <nav class="gcp-guide-nav" aria-label="{{ __('Chapters') }}">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="q" :placeholder="__('Search the guide')" />
            </x-filament::input.wrapper>

            <ol x-init="$el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' })">
                @foreach ($this::chapters() as $slug => $chapter)
                    <li>
                        <a href="{{ $this::chapterUrl($slug) }}" @if ($slug === $current && blank($q)) aria-current="page" @endif>{{ $chapter['title'] }}</a>
                    </li>
                @endforeach
            </ol>
        </nav>

        <x-filament::section>
            @if (filled($q))
                @if ($this->canAsk())
                    <div class="gcp-guide-ask" wire:loading.class="opacity-50" wire:target="ask">
                        @if ($answer)
                            <div class="fi-prose gcp-guide-body">{{ $this->answerHtml() }}</div>
                            <p class="gcp-guide-ask-note">{{ __('Written by the AI assistant from the guide: check it against the sections it links to.') }}</p>
                        @else
                            <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles" wire:click="ask" wire:target="ask" wire:loading.attr="disabled">{{ __('Ask the AI assistant') }}</x-filament::button>
                        @endif
                    </div>
                @endif
                @php($results = $this->results())
                @if ($results === [])
                    <p class="fi-prose">{{ __('Nothing in the guide matches ":search".', ['search' => $q]) }}</p>
                @else
                    <div class="gcp-guide-results">
                        @foreach ($results as $result)
                            <a href="{{ $this::chapterUrl($result['slug'], $result['anchor']) }}" wire:key="result-{{ $loop->index }}">
                                <strong>{{ $result['chapter'] }}@if ($result['section']) › {{ $result['section'] }}@endif</strong>
                                <span>{{ $result['excerpt'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="fi-prose gcp-guide-body">
                    <h1>{{ $this::chapters()[$current]['title'] ?? '' }}</h1>
                    {{ $this->body() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
