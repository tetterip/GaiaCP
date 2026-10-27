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

@once
<style>
    .gx-chips {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .gx-chip {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        min-height: 2.5rem;
        padding: 0 .9rem;
        border-radius: var(--theme-radius-control, .5rem);
        background: var(--theme-card, var(--color-white));
        box-shadow: 0 0 0 1px var(--theme-line, var(--gray-200));
        font-size: .85rem;
        font-weight: 500;
        color: var(--theme-ink, var(--gray-950));
        transition: background-color .2s ease, color .2s ease, transform .15s ease;
    }

    .dark .gx-chip {
        background: var(--theme-card, var(--gray-900));
        box-shadow: 0 0 0 1px var(--theme-line, color-mix(in oklab, var(--color-white) 10%, transparent));
        color: var(--theme-ink, var(--color-white));
    }

    .gx-chip:hover {
        background: var(--theme-soft, var(--gray-50));
    }

    .gx-chip:active {
        transform: scale(.97);
    }

    .gx-chip b {
        font-weight: 700;
    }

    .gx-chip-dot {
        width: .5rem;
        height: .5rem;
        border-radius: 50%;
    }

    .gx-chip.gx-chip-on,
    .dark .gx-chip.gx-chip-on {
        background: var(--gaia-button, var(--primary-600));
        color: var(--gaia-button-ink, var(--color-white));
        box-shadow: none;
    }

    /* A phone: smaller chips on one row that scrolls sideways, rather than several rows over the table */
    @media (max-width: 639px) {
        .gx-chips {
            flex-wrap: nowrap;
            gap: .4rem;
            margin-inline: -1rem;
            padding: 1px 1rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .gx-chips::-webkit-scrollbar {
            display: none;
        }

        .gx-chip {
            flex: none;
            gap: .35rem;
            min-height: 2rem;
            padding: 0 .7rem;
            font-size: .8rem;
            white-space: nowrap;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .gx-chip {
            transition: none;
        }
    }
</style>
@endonce
