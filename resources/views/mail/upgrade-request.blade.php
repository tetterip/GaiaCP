<x-admin-mail :message="$message ?? null">
# {{ __(':client asked to move :service to :product', ['client' => $request->client->name, 'service' => $request->serviceName(), 'product' => $request->product->name]) }}

{{ __('From the client area. Nothing changes until you approve it under Upgrade requests.') }}

**{{ __('Now') }}:** {{ $request->service->product?->name ?? $request->service->label }}, {{ \App\Support\Money::format($request->service->price_cents, $request->service->currency) }} {{ $request->service->billing_cycle->getLabel() }}

**{{ __('Asked for') }}:** {{ $request->product->name }}, {{ \App\Support\Money::format($request->price_cents, $request->service->currency) }} {{ $request->service->billing_cycle->getLabel() }}

@if ($request->notes)
**{{ __('Notes') }}:** {{ $request->notes }}

@endif
<x-mail::button :url="$url">
{{ __('Review the request') }}
</x-mail::button>
</x-admin-mail>
