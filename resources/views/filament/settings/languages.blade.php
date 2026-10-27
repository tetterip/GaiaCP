{{-- Settings → Languages: how complete each language is, the core's texts and each enabled plugin's (App\Support\Translations). --}}
@php
    /** @var \Illuminate\Support\Collection<string, array{name: string, percent: int, missing: array}> $languages */
@endphp
<div style="display: grid; gap: 0.75rem">
    @forelse ($languages as $code => $language)
        @php($missing = $language['missing'])
        <div style="border: 1px solid rgb(127 127 127 / 0.25); border-radius: 0.5rem; padding: 0.75rem 1rem">
            <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap">
                <strong>{{ $language['name'] }} ({{ $code }})</strong>
                <span>{{ $missing['missing'] === 0 ? __('Complete') : trans_choice('{1} :percent% · 1 text missing|[2,*] :percent% · :count texts missing', $missing['missing'], ['percent' => $language['percent']]) }}</span>
            </div>
            @if ($missing['missing'] > 0)
                <ul style="margin: 0.5rem 0 0; padding-left: 1.25rem; opacity: 0.8; font-size: 0.875rem">
                    @if ($missing['core'] !== [])
                        <li>{{ trans_choice('{1} GaiaCP: 1 text|[2,*] GaiaCP: :count texts', count($missing['core'])) }}</li>
                    @endif
                    @foreach ($missing['plugins'] as $plugin)
                        @if ($plugin['missing'] !== [])
                            <li>{{ trans_choice('{1} :plugin: 1 text|[2,*] :plugin: :count texts', count($plugin['missing']), ['plugin' => $plugin['name']]) }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p style="opacity: 0.8">{{ __('Only English so far. Upload translations to add a language.') }}</p>
    @endforelse
</div>
