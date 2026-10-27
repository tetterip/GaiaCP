{{-- The text of mail.password-reset. Not indented: Markdown. --}}
# {{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

@foreach ($outroLines as $line)
{{ $line }}

@endforeach
{{ $salutation }}

<x-mail::subcopy>
{{ __("If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:", ['actionText' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
