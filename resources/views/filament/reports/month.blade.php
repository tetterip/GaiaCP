<label>
    {{ __('Month') }}
    <x-filament::input.wrapper>
        <x-filament::input.select wire:model.live="month">
            @foreach ($this->months() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>
