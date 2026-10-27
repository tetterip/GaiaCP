<x-filament-panels::page>
    {{-- No custom theme is compiled, so the page carries its own CSS on Filament's color variables. --}}
    <style>
        .gcp-api { display: grid; gap: 1.5rem; max-width: 60rem; }
        .gcp-api h2 { font-size: 1rem; font-weight: 600; color: var(--gray-950); margin-bottom: .5rem; }
        .dark .gcp-api h2 { color: #fff; }
        .gcp-api-list { display: grid; gap: .5rem; }
        .gcp-api details { border: 1px solid var(--gray-200); border-radius: .75rem; background: #fff; }
        .dark .gcp-api details { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .gcp-api summary { display: flex; flex-wrap: wrap; gap: .25rem .6rem; align-items: center; padding: .7rem .9rem; cursor: pointer; list-style: none; }
        .gcp-api summary::-webkit-details-marker { display: none; }
        .gcp-api-method { flex: none; min-width: 4rem; text-align: center; padding: .1rem .4rem; border-radius: .375rem; font: 600 .7rem/1.4 ui-monospace, monospace; text-transform: uppercase; color: #fff; }
        .gcp-api-get { background: var(--info-600, #2563eb); }
        .gcp-api-post { background: var(--success-600); }
        .gcp-api-patch { background: var(--warning-600); }
        .gcp-api-delete { background: var(--danger-600); }
        .gcp-api-path { font: 500 .8125rem/1.4 ui-monospace, monospace; color: var(--gray-950); overflow-wrap: anywhere; }
        .dark .gcp-api-path { color: #fff; }
        .gcp-api-summary { font-size: .8125rem; color: var(--gray-500); }
        .gcp-api-body { padding: 0 .9rem .9rem; font-size: .8125rem; line-height: 1.5; color: var(--gray-600); display: grid; gap: .6rem; }
        .dark .gcp-api-body { color: var(--gray-300); }
        .gcp-api code { font: .75rem ui-monospace, monospace; padding: .05rem .3rem; border-radius: .25rem; background: var(--gray-100); color: var(--gray-800); }
        .dark .gcp-api code { background: rgb(255 255 255 / .08); color: var(--gray-200); }
        .gcp-api table { width: 100%; border-collapse: collapse; }
        .gcp-api th { text-align: start; font-weight: 600; color: var(--gray-700); padding: .3rem .5rem .3rem 0; }
        .dark .gcp-api th { color: var(--gray-200); }
        .gcp-api td { vertical-align: top; padding: .3rem .5rem .3rem 0; border-top: 1px solid var(--gray-100); }
        .dark .gcp-api td { border-color: rgb(255 255 255 / .06); }
        .gcp-api-scroll { overflow-x: auto; }
        .gcp-api pre { margin: 0; padding: .75rem; border-radius: .5rem; background: var(--gray-950); color: #e5e7eb; font: .75rem/1.5 ui-monospace, monospace; overflow-x: auto; white-space: pre; }
    </style>

    @php($areas = ['key' => __('The key')] + \App\Models\ApiKey::areaOptions())
    <div class="gcp-api">
        <section>
            <h2>{{ __('Example') }}</h2>
            <pre>curl {{ url('api/v1/clients') }}?search=papadopoulos \
  -H "Authorization: Bearer gcp_…" \
  -H "Accept: application/json"</pre>
        </section>

        @foreach ($this->endpoints as $area => $endpoints)
            <section>
                <h2>{{ $areas[$area] ?? $area }}</h2>
                <div class="gcp-api-list">
                    @foreach ($endpoints as $endpoint)
                        <details wire:key="api-{{ $endpoint['method'] }}-{{ $endpoint['path'] }}">
                            <summary>
                                <span class="gcp-api-method gcp-api-{{ $endpoint['method'] }}">{{ $endpoint['method'] }}</span>
                                <span class="gcp-api-path">{{ $endpoint['path'] }}</span>
                                <span class="gcp-api-summary">{{ $endpoint['summary'] }}</span>
                            </summary>
                            <div class="gcp-api-body">
                                @if (filled($endpoint['description'] ?? null))
                                    <p>{{ $endpoint['description'] }}</p>
                                @endif
                                <p>
                                    {{ __('Permission') }}:
                                    @if ($endpoint['permission'])
                                        <code>{{ $endpoint['permission'] }}</code>
                                    @else
                                        {{ __('any key') }}
                                    @endif
                                </p>
                                @if (! empty($endpoint['query']))
                                    <p>{{ __('Filters') }}: @foreach ($endpoint['query'] as $name)<code>{{ $name }}</code>@if (! $loop->last), @endif @endforeach</p>
                                @endif
                                @if ($endpoint['fields'] !== [])
                                    <div class="gcp-api-scroll">
                                        <table>
                                            <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Type') }}</th><th></th></tr></thead>
                                            <tbody>
                                                @foreach ($endpoint['fields'] as $field)
                                                    <tr>
                                                        <td><code>{{ $field['name'] }}</code>@if ($field['required']) *@endif</td>
                                                        <td>{{ $field['type'] }}</td>
                                                        <td>{{ $field['description'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
