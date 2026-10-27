<x-mail::message>
# {{ $title }}

{{ __('This domain uses the Email Notifications registrar, so nothing was booked at any registrar. Please do it by hand, then update the domain here (expiry date, nameservers) if anything changed.') }}

@if ($client)
{{ __('Client') }}: {{ $client->name }}@if (filled($client->company)) ({{ $client->company }})@endif<br>
@endif
@if ($registrant)
{{ __('Registrant') }}: {{ $registrant->name }}@if (filled($registrant->company)), {{ $registrant->company }}@endif<br>
{{ __('Email address') }}: {{ $registrant->email }}<br>
@if (filled($registrant->phone))
{{ __('Phone number') }}: {{ $registrant->phone }}<br>
@endif
@if (filled($address))
{{ __('Address') }}: {{ $address }}<br>
@endif
@if (filled($registrant->vat_number))
{{ __('VAT number') }}: {{ $registrant->vat_number }}<br>
@endif
@endif
@if ($nameservers !== [])
{{ __('Nameservers') }}: {{ implode(', ', $nameservers) }}<br>
@endif
@if ($request === \Plugins\EmailRegistrar\RegistrarRequestMail::TRANSFER)
{{ __('The EPP code is on the domain\'s page.') }}
@endif

@if ($url)
<x-mail::button :url="$url">
{{ __('Open the domain') }}
</x-mail::button>
@endif
</x-mail::message>
