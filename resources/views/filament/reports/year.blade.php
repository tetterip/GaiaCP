<label>
    {{ __('Year') }}
    <x-filament::input.wrapper>
        <x-filament::input.select wire:model.live="year">
            @foreach ($this->years() as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>
