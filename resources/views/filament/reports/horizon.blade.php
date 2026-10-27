<label>
    {{ __('Months ahead') }}
    <x-filament::input.wrapper>
        <x-filament::input.select wire:model.live="months">
            @foreach ([6, 12, 24, 36] as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>
