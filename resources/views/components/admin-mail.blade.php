@props(['message' => null])
{{-- Every system email to the admins: the GaiaCP logo on top (as in /admin), not the company's. Not indented: Markdown. --}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($logoSrc = \App\Mail\MailLayout::gaiacpLogo($message))
<img src="{{ $logoSrc }}" alt="GaiaCP" style="height: 40px; max-width: 100%;">
@else
GaiaCP
@endif
</x-mail::header>
</x-slot:header>
{!! $slot !!}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
