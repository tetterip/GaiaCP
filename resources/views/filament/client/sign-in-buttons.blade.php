{{-- Under the client area's login and sign up forms: the sign-in plugins' buttons (ClientPanelProvider); styled by
     filament.themes.client. --}}
<div class="gcp-signin">
    <div class="gcp-signin-or">{{ __('or') }}</div>
    @foreach ($buttons as $button)
        {!! $button->toHtml() !!}
    @endforeach
</div>
