{{-- The order form's compare table (OrderForm::compare()): the group's features in rows, a column for each product;
     "Yes" / "No" (or ✓ / ✗) show as marks, other values as typed. Then the price of each billing cycle and a Choose
     button per column, which sets $field (the form's product field) as the product cards do. Styled by
     filament.themes.client. --}}
@php
    $mark = function (?string $value): ?string {
        $plain = mb_strtolower(trim((string) $value));

        return match (true) {
            $plain === '' || in_array($plain, ['no', 'όχι', 'οχι', '✗', '-', '—', 'x'], true) => 'no',
            in_array($plain, ['yes', 'ναι', '✓', '✔'], true) => 'yes',
            default => null,
        };
    };
@endphp
<details class="gcp-compare" open>
    <summary>{{ __('Compare packages') }}</summary>
    <div class="gcp-compare-wrap">
        <table class="gcp-compare-table">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($compare['products'] as $product)
                        <th @class(['gcp-compare-chosen' => $product['id'] === $chosen])>
                            @if ($product['popular'])
                                <span class="gcp-popular">{{ __('Popular') }}</span>
                            @endif
                            <span class="gcp-compare-name">{{ $product['name'] }}</span>
                            <span class="gcp-compare-price">{{ $product['price'] }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($compare['features'] as $key => $feature)
                    <tr>
                        <th scope="row">{{ $feature }}</th>
                        @foreach ($compare['products'] as $product)
                            @php($value = $product['values'][$key] ?? null)
                            <td @class(['gcp-compare-chosen' => $product['id'] === $chosen])>
                                @switch($mark($value))
                                    @case('yes')
                                        <x-filament::icon icon="heroicon-m-check" class="gcp-compare-yes" :label="__('Yes')" />
                                        @break
                                    @case('no')
                                        <span class="gcp-compare-no" aria-label="{{ __('No') }}">—</span>
                                        @break
                                    @default
                                        {{ $value }}
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                @foreach ($compare['cycles'] as $cycle => $label)
                    <tr @class(['gcp-compare-cycle', 'gcp-compare-first-cycle' => $loop->first])>
                        <th scope="row">{{ $label }}</th>
                        @foreach ($compare['products'] as $product)
                            @php($price = $product['prices'][$cycle] ?? null)
                            <td @class(['gcp-compare-chosen' => $product['id'] === $chosen])>
                                @if ($price)
                                    <span class="gcp-compare-amount">{{ $price['price'] }}</span>
                                    @if ($price['note'])
                                        <span class="gcp-compare-price">{{ $price['note'] }}</span>
                                    @endif
                                @else
                                    <span class="gcp-compare-no" aria-label="{{ __('Not offered') }}">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th></th>
                    @foreach ($compare['products'] as $product)
                        <td @class(['gcp-compare-chosen' => $product['id'] === $chosen])>
                            @if ($product['id'] === $chosen)
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-check" disabled>{{ __('Chosen') }}</x-filament::button>
                            @else
                                <x-filament::button size="sm" wire:click="$set('{{ $field }}', '{{ $product['id'] }}')">{{ __('Choose') }}</x-filament::button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</details>
