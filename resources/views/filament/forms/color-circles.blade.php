{{-- A colour picker (App\Filament\Forms\ColorCircles): a circle for each colour, the chosen one ringed with a check
     mark; "None" first when the colour is optional. --}}
@php($statePath = $getStatePath())
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{ state: $wire.$entangle(@js($statePath)) }"
        role="radiogroup"
        aria-label="{{ $getLabel() }}"
        style="display: flex; flex-wrap: wrap; gap: .6rem; padding: .25rem 0"
    >
        @if ($optional)
            <button
                type="button"
                role="radio"
                x-on:click="state = null"
                x-bind:aria-checked="(state === null || state === '') ? 'true' : 'false'"
                x-bind:style="{ outline: (state === null || state === '') ? '2px solid rgb(156 163 175)' : 'none' }"
                title="{{ __('None') }}"
                aria-label="{{ __('None') }}"
                style="width: 2rem; height: 2rem; border-radius: 999px; outline-offset: 2px; border: 2px dashed rgb(156 163 175); background: transparent; cursor: pointer"
            ></button>
        @endif
        @foreach ($colors as $key => $color)
            <button
                type="button"
                role="radio"
                x-on:click="state = @js($key)"
                x-bind:aria-checked="state === @js($key) ? 'true' : 'false'"
                x-bind:style="{ outline: state === @js($key) ? '2px solid {{ $color['hex'] }}' : 'none' }"
                title="{{ $color['label'] }}"
                aria-label="{{ $color['label'] }}"
                style="display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 999px; outline-offset: 2px; border: 0; background: {{ $color['hex'] }}; color: #fff; cursor: pointer"
            >
                <svg x-show="state === @js($key)" x-cloak viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" style="width: 1.1rem; height: 1.1rem">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
            </button>
        @endforeach
    </div>
</x-dynamic-component>
