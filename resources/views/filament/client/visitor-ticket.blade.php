{{-- App\Filament\Client\Pages\VisitorTicket: the ticket's messages beside its details, at its secret link. --}}
@php
    $ticket = $this->ticket();
@endphp
<x-filament-panels::page>
    <div class="gcp-visitor-ticket">
        <div>
            @include('filament.tickets.thread', ['getRecord' => fn () => $ticket, 'panel' => 'client', 'fileUrl' => fn ($file) => route('filament.client.support-ticket-file', ['token' => $ticket->access_token, 'attachment' => $file->id])])
        </div>
        <x-filament::section>
            <dl class="gcp-visitor-ticket-details">
                @foreach ($this->details() as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
            <p class="gcp-home-note">{{ __('Keep this link to yourself: anyone who has it can read and answer this ticket.') }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
