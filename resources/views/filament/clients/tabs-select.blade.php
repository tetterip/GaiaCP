{{-- On a phone the client page's tabs are this select; wider screens show the tabs. --}}
<style>
    .gcp-client-tabs-select { display: none; }
    /* Eight tabs: wrap to a second row rather than scroll out of sight */
    .gcp-client-tabs > .fi-tabs { flex-wrap: wrap; overflow-x: visible; }
    @media (max-width: 639px) {
        .gcp-client-tabs-select { display: block; margin-bottom: 1rem; }
        .gcp-client-tabs > .fi-tabs { display: none; }
    }
</style>

<div class="gcp-client-tabs-select">
    <label for="gcp-client-tab" class="sr-only">{{ __('Show') }}</label>
    <x-filament::input.wrapper>
        <x-filament::input.select id="gcp-client-tab" wire:model.live="activeRelationManager">
            @foreach ($options as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</div>
