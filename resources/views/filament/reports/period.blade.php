<label>
    {{ __('From') }}
    <x-filament::input.wrapper>
        <x-filament::input type="date" wire:model.live.debounce.500ms="from" />
    </x-filament::input.wrapper>
</label>
<label>
    {{ __('Until') }}
    <x-filament::input.wrapper>
        <x-filament::input type="date" wire:model.live.debounce.500ms="until" />
    </x-filament::input.wrapper>
</label>
<span style="display: flex; gap: .5rem; flex-wrap: wrap">
    @foreach ($this->presets() as $key => $label)
        <x-filament::button color="gray" size="sm" wire:click="preset('{{ $key }}')">{{ $label }}</x-filament::button>
    @endforeach
</span>
