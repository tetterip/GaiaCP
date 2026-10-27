{{-- Contact us: the company's details (App\Support\PublicArea::contactLines()), opening hours and an Open in maps
     button; for a client, Open a ticket. Styled by filament.themes.client. --}}
@php
    use App\Support\PublicArea;

    $icons = [
        'address' => 'heroicon-o-map-pin',
        'phone' => 'heroicon-o-phone',
        'email' => 'heroicon-o-envelope',
        'website' => 'heroicon-o-globe-alt',
        'vat_number' => 'heroicon-o-identification',
    ];
    $hours = PublicArea::contactHours();
    $map = PublicArea::mapUrl();
@endphp
<section class="gcp-card gcp-contact">
    <h2 class="gcp-contact-name">{{ config('gaiacp.company.name') ?: 'GaiaCP' }}</h2>

    @foreach (PublicArea::contactLines() as $line)
        <div class="gcp-contact-line">
            <x-filament::icon :icon="$icons[$line['key']]" class="gcp-contact-icon" />
            <div>
                <span class="gcp-home-note">{{ $line['label'] }}</span>
                @if ($line['url'])
                    <a href="{{ $line['url'] }}" @if ($line['key'] === 'website') target="_blank" rel="noopener" @endif>{{ $line['value'] }}</a>
                @else
                    <span class="gcp-contact-value">{{ $line['value'] }}</span>
                @endif
            </div>
        </div>
    @endforeach

    @if ($hours)
        <div class="gcp-contact-line">
            <x-filament::icon icon="heroicon-o-clock" class="gcp-contact-icon" />
            <div>
                <span class="gcp-home-note">{{ __('Opening hours') }}</span>
                <span class="gcp-contact-value">{{ $hours }}</span>
            </div>
        </div>
    @endif

    @if ($map || $ticketUrl)
        <div class="gcp-contact-actions">
            @if ($ticketUrl)
                <x-filament::button tag="a" :href="$ticketUrl" icon="heroicon-o-chat-bubble-left-right">{{ __('Open a ticket') }}</x-filament::button>
            @endif
            @if ($map)
                <x-filament::button tag="a" :href="$map" target="_blank" rel="noopener" color="gray" icon="heroicon-o-map">{{ __('Open in maps') }}</x-filament::button>
            @endif
        </div>
    @endif
</section>
