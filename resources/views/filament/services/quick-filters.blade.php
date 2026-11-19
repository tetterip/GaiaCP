{{-- Quick filters over a list: the services (ListServices::quickFilters(), a chip per status and "due soon", each with
     its count in the open tab) and payment notices (HasStatusChips, which passes its own dots). In Filament's colours,
     so they work with any theme or none. --}}
@php
    $dots ??= ['active' => 'success', 'suspended' => 'danger', 'pending' => 'warning', 'due_soon' => 'primary'];
@endphp
<div class="gx-chips" role="group" aria-label="{{ __('Quick filters') }}">
    @foreach ($filters as $key => $filter)
        <button
            type="button"
            wire:click="applyQuickFilter('{{ $key }}')"
            wire:loading.attr="disabled"
            @class(['gx-chip', 'gx-chip-on' => $filter['active']])
            aria-pressed="{{ $filter['active'] ? 'true' : 'false' }}"
        >
            @isset($dots[$key])
                <span class="gx-chip-dot" style="background: var(--{{ $dots[$key] }}-500)"></span>
            @endisset
            {{ $filter['label'] }}
            <b>{{ $filter['count'] }}</b>
        </button>
    @endforeach
</div>

@include('filament.services.chips-style')
