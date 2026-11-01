{{-- "Last used" on a sign-in button: the way this browser last logged in to this panel (App\SignIn\SignIn::lastUsed()). --}}
@if (\App\SignIn\SignIn::lastUsed(filament()->getCurrentOrDefaultPanel()->getId()) === $method)
    <span class="gcp-signin-last">{{ __('Last used') }}</span>
@endif
