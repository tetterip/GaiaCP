<x-admin-mail :message="$message ?? null">
# {{ __(':client asked to cancel :service', ['client' => $request->client->name, 'service' => $request->serviceName()]) }}

{{ __('From the client area. Nothing changes until you approve it under Cancellation requests.') }}

**{{ __('When') }}:** {{ $request->type->getLabel() }}@if ($request->type === \App\Enums\CancellationType::EndOfPeriod) ({{ $request->service->next_due_date?->format(\App\Providers\AppServiceProvider::dateFormat()) }})@endif

@if ($request->domainService)
**{{ __('Domain') }}:** {{ __('Do not renew :domain', ['domain' => $request->domainService->domain]) }}

@endif
@if ($request->reason)
**{{ __('Reason') }}:** {{ $request->reason }}

@endif
<x-mail::button :url="$url">
{{ __('Review the request') }}
</x-mail::button>
</x-admin-mail>
