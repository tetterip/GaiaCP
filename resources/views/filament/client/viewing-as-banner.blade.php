{{-- Top of every client area page while an admin looks at it as a client (ClientAreaAccess::logInAs()). --}}
<div class="gcp-maintenance gcp-viewing-as" role="status">
    {{ __('You are viewing the client area as :name.', ['name' => auth('client')->user()->client?->displayName() ?? auth('client')->user()->name]) }}
    <a href="{{ route('filament.client.stop-viewing') }}">{{ __('Back to admin') }}</a>
</div>
