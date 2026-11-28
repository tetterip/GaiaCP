<div>
    {{-- The hosting page's WordPress sites (App\Livewire\WordPressSites); no custom theme is compiled, so the rows carry their own CSS. --}}
    <style>
        .gcp-wp { display: grid; gap: .5rem; }
        .gcp-wp-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .75rem .875rem; border-radius: .75rem; border: 1px solid var(--gray-200); }
        .gcp-wp-site { display: flex; align-items: center; gap: .75rem; min-width: 0; }
        .gcp-wp-site > svg { flex: none; width: 1.5rem; height: 1.5rem; color: var(--primary-600); }
        .gcp-wp-title { font-size: .9375rem; font-weight: 600; color: var(--gray-950); overflow-wrap: anywhere; }
        .gcp-wp-url { font-size: .8125rem; color: var(--gray-500); overflow-wrap: anywhere; }
        .gcp-wp-url a:hover { text-decoration: underline; }
        .gcp-wp-empty { font-size: .875rem; color: var(--gray-600); }
        .dark .gcp-wp-row { border-color: rgb(255 255 255 / .1); }
        .dark .gcp-wp-site > svg { color: var(--primary-400); }
        .dark .gcp-wp-title { color: #fff; }
        .dark .gcp-wp-url, .dark .gcp-wp-empty { color: var(--gray-400); }
    </style>

    @if ($sites === null)
        <div class="gcp-wp-empty">{{ __('Your WordPress sites can\'t be shown right now. Please try again later.') }}</div>
    @elseif ($sites === [])
        <div class="gcp-wp-empty">{{ __('There is no WordPress site on this account yet. Install WordPress adds one.') }}</div>
    @else
        <div class="gcp-wp">
            @foreach ($sites as $site)
                <div class="gcp-wp-row">
                    <div class="gcp-wp-site">
                        @svg('heroicon-o-window')
                        <div style="min-width: 0">
                            <div class="gcp-wp-title">{{ $site->title ?? $site->url }}</div>
                            <div class="gcp-wp-url">
                                <a href="{{ $site->url }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', rtrim($site->url, '/')) }}</a>
                                @if ($site->version)
                                    · {{ __('WordPress :version', ['version' => $site->version]) }}
                                @endif
                            </div>
                        </div>
                    </div>
                    <x-filament::button
                        tag="a"
                        size="sm"
                        icon="heroicon-o-arrow-top-right-on-square"
                        :href="route('filament.client.services.wordpress-login', ['service' => $service->id, 'site' => $site->id])"
                        target="_blank"
                        rel="noopener"
                    >
                        {{ __('Log in to WordPress') }}
                    </x-filament::button>
                </div>
            @endforeach
        </div>
    @endif
</div>
