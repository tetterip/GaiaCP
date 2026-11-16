<x-admin-mail :message="$message ?? null">
# {{ __(':client asked for the EPP code of :domain', ['client' => $service->client->name, 'domain' => $service->domain]) }}

{{ __(':registrar gives no EPP code through its API. Read it in your :registrar account, then press Send EPP code in the domain\'s Registrar menu: the client gets it by email.', ['registrar' => $registrar]) }}

<x-mail::button :url="$url">
{{ __('Open the domain') }}
</x-mail::button>
</x-admin-mail>
