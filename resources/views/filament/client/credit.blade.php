{{-- App\Filament\Client\Pages\Credit: the balance over the history. --}}
<x-filament-panels::page>
    @include('filament.dashboard.styles')
    <section class="fi-section gx-card gcp-credit-balance">
        <div class="gx-hero-label">{{ __('Your credit') }}</div>
        <div class="gx-big">{{ $this->balance() }}</div>
        <div class="gx-muted">{{ __('Used to pay your payment notices.') }}</div>
    </section>

    {{ $this->table }}
</x-filament-panels::page>
