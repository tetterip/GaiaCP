<x-filament-panels::page>
    {{-- No custom theme is compiled, so the cards carry their own CSS on Filament's color variables. --}}
    <style>
        .gcp-settings { display: grid; gap: 1.75rem; }
        .gcp-settings h2 { margin: 0 0 .6rem; font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--gray-500); }
        .gcp-settings-grid { display: grid; gap: .875rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
        .gcp-settings-card { display: flex; gap: .875rem; align-items: flex-start; padding: 1rem 1.1rem; border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); text-decoration: none; transition: border-color .15s, box-shadow .15s; }
        .gcp-settings-card:hover, .gcp-settings-card:focus-visible { border-color: var(--primary-500); box-shadow: 0 1px 6px -2px color-mix(in oklab, var(--primary-500) 40%, transparent); outline: none; }
        .gcp-settings-icon { flex: none; display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: .5rem; background: var(--primary-50); color: var(--primary-600); }
        .gcp-settings-icon svg { width: 1.25rem; height: 1.25rem; }
        .gcp-settings-text { flex: 1; min-width: 0; }
        .gcp-settings-title { display: block; font-weight: 600; color: var(--gray-950); }
        .gcp-settings-desc { display: block; margin-top: .15rem; font-size: .8125rem; line-height: 1.4; color: var(--gray-500); }
        .gcp-settings-chevron { flex: none; width: 1rem; height: 1rem; margin-top: .2rem; color: var(--gray-400); }
        .dark .gcp-settings-card { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .dark .gcp-settings-card:hover, .dark .gcp-settings-card:focus-visible { border-color: var(--primary-400); }
        .dark .gcp-settings-icon { background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-400); }
        .dark .gcp-settings-title { color: #fff; }
        .dark .gcp-settings-desc { color: var(--gray-400); }
        .gcp-settings-search { width: 100%; max-width: 28rem; padding: .55rem .8rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: #fff; color: var(--gray-950); font-size: .875rem; }
        .gcp-settings-search:focus { outline: none; border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
        .dark .gcp-settings-search { background: var(--gray-900); border-color: rgb(255 255 255 / .15); color: #fff; }
        .gcp-settings-none { color: var(--gray-500); font-size: .875rem; }
    </style>

    {{-- Search: filters the cards by name, description and group, ignoring case and Greek accents. --}}
    <div
        class="gcp-settings"
        x-data="{
            q: '',
            norm: (text) => text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''),
            shows(text) { return this.q.trim() === '' || this.norm(text).includes(this.norm(this.q.trim())) },
        }"
    >
        <input type="search" x-model="q" class="gcp-settings-search" placeholder="{{ __('Search settings') }}" aria-label="{{ __('Search settings') }}" autofocus />
        @php($cardGroups = $this->cards())
        @foreach ($cardGroups as $group => $cards)
            @php($texts = $cards->map(fn (array $card) => $group.' '.$card['label'].' '.$card['description'])->values())
            <section x-show="@js($texts).some((text) => shows(text))">
                <h2>{{ $group }}</h2>
                <div class="gcp-settings-grid">
                    @foreach ($cards as $card)
                        <a href="{{ $card['url'] }}" class="gcp-settings-card" x-show="shows(@js($group.' '.$card['label'].' '.$card['description']))">
                            <span class="gcp-settings-icon">
                                <x-filament::icon :icon="$card['icon']" />
                            </span>
                            <span class="gcp-settings-text">
                                <span class="gcp-settings-title">{{ $card['label'] }}</span>
                                <span class="gcp-settings-desc">{{ $card['description'] }}</span>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="gcp-settings-chevron" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        <p class="gcp-settings-none" x-cloak x-show="! @js($cardGroups->flatMap(fn ($cards, $group) => $cards->map(fn (array $card) => $group.' '.$card['label'].' '.$card['description']))->values()).some((text) => shows(text))">{{ __('No settings match your search.') }}</p>
    </div>
</x-filament-panels::page>
