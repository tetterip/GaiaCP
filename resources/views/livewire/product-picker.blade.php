{{-- "New hosting" / "New service" (App\Livewire\ProductPicker): a chip per product group, then a card per product with
     its cycle, price and Add to cart; one that needs a domain asks for it in the card. --}}
@php
    $groups = $this->groups();
@endphp
<div class="gcp-picker">
    @include('filament.services.chips-style')
    @if ($groups->count() > 1)
        <div class="gx-chips" role="group" aria-label="{{ __('Product groups') }}">
            @foreach ($groups as $key => $entry)
                <button type="button" wire:click="pickGroup('{{ $key }}')" @class(['gx-chip', 'gx-chip-on' => $key === $group]) aria-pressed="{{ $key === $group ? 'true' : 'false' }}">
                    {{ $entry['name'] }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="gcp-picker-grid">
        @foreach ($this->products() as $product)
            <div class="gcp-picker-card" wire:key="picker-{{ $product->id }}">
                <div class="gcp-picker-name">{{ $product->localName() }}</div>
                @if (filled($summary = $this->summary($product)))
                    <div class="gcp-picker-summary">{{ $summary }}</div>
                @endif

                <label class="gcp-cart-line-choice">
                    <span>{{ __('Billing cycle') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="cycles.{{ $product->id }}">
                            @foreach ($this->cycleOptions($product) as $value => $label)
                                <option value="{{ $value }}" @selected($value === $this->cycleOf($product))>{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                @if ($pending === $product->id)
                    <div class="gcp-picker-domain">
                        <label class="gcp-cart-line-choice">
                            <span>{{ __('Domain name') }}</span>
                            <x-filament::input.wrapper :valid="! $errors->has('domain')">
                                <x-filament::input type="text" wire:model="domain" wire:keydown.enter="add({{ $product->id }})" placeholder="example.gr" autofocus />
                            </x-filament::input.wrapper>
                        </label>
                        @if ($product->type === \App\Enums\ServiceType::Hosting && $this->canRegister())
                            <div class="gcp-picker-options">
                                <label><input type="radio" wire:model="domainOption" value="{{ \App\Actions\ClientOrder::OWN }}"> {{ __('I already have it') }}</label>
                                <label><input type="radio" wire:model="domainOption" value="{{ \App\Actions\ClientOrder::REGISTER }}"> {{ __('Register it with this order') }}</label>
                            </div>
                        @endif
                        @error('domain')
                            <div class="gcp-cart-line-error">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <div class="gcp-picker-actions">
                    <x-filament::button wire:click="add({{ $product->id }})" icon="heroicon-o-shopping-cart" class="gcp-picker-add">
                        {{ __('Add to cart') }}
                    </x-filament::button>
                    @if ($pending === $product->id)
                        <x-filament::link tag="button" color="gray" wire:click="cancelDomain">{{ __('Cancel') }}</x-filament::link>
                    @elseif ($this->hasMoreChoices($product))
                        <x-filament::link :href="$this->orderUrl($product)" color="gray">{{ __('More choices') }}</x-filament::link>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
