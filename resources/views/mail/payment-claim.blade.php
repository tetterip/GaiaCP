<x-admin-mail :message="$message ?? null">
# {{ __(':client says they paid :number by :method', ['client' => $invoice->client->name, 'number' => $invoice->number, 'method' => $method]) }}

{{ __('From the client area. Nothing is recorded yet: check that the money arrived, then record the payment on the notice, or reject the claim if it did not.') }}

**{{ __('Amount') }}:** {{ $amount }}

**{{ __('Reason') }}:** {{ $invoice->number }}

<x-mail::button :url="$url">
{{ __('Open the payment notice') }}
</x-mail::button>
</x-admin-mail>
