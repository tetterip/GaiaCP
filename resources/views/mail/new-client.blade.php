<x-admin-mail :message="$message ?? null">
# {{ __('New client: :client', ['client' => $client->name]) }}

@if ($provider)
{{ __('Signed up in the client area with :provider, which confirmed the email address.', ['provider' => $provider]) }}
@else
{{ __('Signed up in the client area. They can log in once they confirm their email address.') }}
@endif

{{ __('Email address') }}: {{ $client->email }}<br>
@if (filled($client->company))
{{ __('Company name') }}: {{ $client->company }}<br>
@endif
@if (filled($client->phone))
{{ __('Phone number') }}: {{ $client->phone }}
@endif

<x-mail::button :url="$url">
{{ __('Open the client') }}
</x-mail::button>
</x-admin-mail>
