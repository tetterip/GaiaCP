{{-- The order form's compare table (OrderForm::compare()): the group's features in rows, a column for each product;
     "Yes" / "No" (or ✓ / ✗) show as marks, other values as typed. Then the price of each billing cycle and a Choose
     button per column, which sets $field (the form's product field) as the product cards do, or, on a group's public
     page (ProductGroupPage, no $field), an Order link to each product's url. Styled by
     filament.themes.client; a product's colour (data-gcp-color) colours its column's name, marks and button.
     On a narrow screen the whole table scrolls sideways; it gives a small sideways nudge when it first comes into view
     and each time the chosen package changes (data-chosen, changed by Livewire), as a hint that it scrolls. --}}
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
    <summary>{{ $title ?? __('Compare packages') }}</summary>
    <div
        class="gcp-compare-wrap"
        data-chosen="{{ $chosen }}"
        x-data="{
            nudge() {
                const el = this.$el;
                const room = el.scrollWidth - el.clientWidth;
                if (room < 8 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                const rtl = getComputedStyle(el).direction === 'rtl';
                const start = el.scrollLeft;
                const towardEnd = Math.abs(start) < room - 4;
                const step = Math.min(56, room) * (towardEnd ? 1 : -1) * (rtl ? -1 : 1);
                el.scrollTo({ left: start + step, behavior: 'smooth' });
                setTimeout(() => el.scrollTo({ left: start, behavior: 'smooth' }), 380);
            },
        }"
        x-init="
            new IntersectionObserver((entries, seen) => {
                if (entries.some((entry) => entry.isIntersecting)) { seen.disconnect(); setTimeout(() => nudge(), 300); }
            }, { threshold: 0.5 }).observe($el);
            new MutationObserver(() => nudge()).observe($el, { attributes: true, attributeFilter: ['data-chosen'] });
        "
    >
        <table class="gcp-compare-table">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($compare['products'] as $product)
                        <th @class(['gcp-compare-chosen' => $product['id'] === $chosen]) @if ($product['color']) data-gcp-color="{{ $product['color'] }}" @endif>
                            @if ($product['sale'] || $product['popular'] || $product['badges'] !== [])
                                <span class="gcp-compare-badges">@include('filament.client.product-badges', ['sale' => $product['sale'], 'popular' => $product['popular'], 'badges' => $product['badges']])</span>
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
                            <td @class(['gcp-compare-chosen' => $product['id'] === $chosen]) @if ($product['color']) data-gcp-color="{{ $product['color'] }}" @endif>
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
                            <td @class(['gcp-compare-chosen' => $product['id'] === $chosen]) @if ($product['color']) data-gcp-color="{{ $product['color'] }}" @endif>
                                @if ($price)
                                    @if ($price['was'])
                                        <s class="gcp-was">{{ $price['was'] }}</s>
                                    @endif
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
                        <td @class(['gcp-compare-chosen' => $product['id'] === $chosen]) @if ($product['color']) data-gcp-color="{{ $product['color'] }}" @endif>
                            @if (! isset($field))
                                <x-filament::button tag="a" size="sm" :href="$product['url']">{{ __('Order') }}</x-filament::button>
                            @elseif ($product['id'] === $chosen)
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
