{{-- A sign-in provider's button (Plugin::clientLoginButtons(), adminLoginButtons()), styled by the sign-in options'
     styles; $method (e.g. google) marks it "Last used" when this browser last came in that way. --}}
<a href="{{ $url }}" class="gcp-signin-button">{!! $icon !!}<span>{{ $label }}</span>@isset($method)@include('filament.sign-in.last-used')@endisset</a>
