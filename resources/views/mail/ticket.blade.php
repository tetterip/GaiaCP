<x-admin-mail :message="$message ?? null">
# {{ $new ? __('New ticket :number: :subject', ['number' => $ticket->number, 'subject' => $ticket->subject]) : __('Client replied to :number: :subject', ['number' => $ticket->number, 'subject' => $ticket->subject]) }}

**{{ __('Client') }}:** {{ $ticket->requesterName() ?: '-' }}{{ $ticket->client_id || blank($ticket->email) ? '' : ' ('.$ticket->email.')' }}

@if ($ticket->department)
**{{ __('Department') }}:** {{ $ticket->department->name }}

@endif
**{{ __('Priority') }}:** {{ $ticket->priority->getLabel() }}

<x-mail::panel>
{!! nl2br(e($body), false) !!}
</x-mail::panel>

@if ($files)
{{ __(':count file(s) attached', ['count' => $files]) }}

@endif
<x-mail::button :url="$url">
{{ __('Open the ticket') }}
</x-mail::button>
</x-admin-mail>
