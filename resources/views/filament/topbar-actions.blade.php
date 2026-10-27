{{-- The top bar's quick actions after the search (GLOBAL_SEARCH_AFTER): the client area in a new tab and New order,
     the second only for admins who may create orders. Icons only on a phone. --}}
@php
    use App\Filament\Resources\Orders\OrderResource;
    use Filament\Facades\Filament;
    use Filament\Support\Icons\Heroicon;
@endphp
<div class="fi-topbar-actions">
    <x-filament::button
        tag="a"
        :href="Filament::getPanel('client')->getUrl()"
        target="_blank"
        color="gray"
        outlined
        :icon="Heroicon::OutlinedArrowTopRightOnSquare"
        :aria-label="__('Client area')"
        class="fi-topbar-action fi-topbar-client-area"
    >
        <span class="fi-topbar-action-label">{{ __('Client area') }}</span>
    </x-filament::button>

    @if (OrderResource::canAccess() && OrderResource::canCreate())
        <x-filament::button
            tag="a"
            :href="OrderResource::getUrl('create')"
            :icon="Heroicon::OutlinedPlus"
            :aria-label="__('New order')"
            class="fi-topbar-action fi-topbar-new-order"
        >
            <span class="fi-topbar-action-label">{{ __('New order') }}</span>
        </x-filament::button>
    @endif
</div>
