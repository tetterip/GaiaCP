@props(['message' => null])
{{-- Every email to a client: the company logo (Settings → Company) or name on top and the company line at the bottom. Not indented: Markdown. --}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="\App\Mail\MailLayout::companyUrl()">
@if ($logoSrc = \App\Mail\MailLayout::companyLogo($message))
<img src="{{ $logoSrc }}" alt="{{ \App\Mail\MailLayout::companyName() }}" style="max-height: 80px; max-width: 100%;">
@else
{{ \App\Mail\MailLayout::companyName() }}
@endif
</x-mail::header>
</x-slot:header>
{!! $slot !!}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \App\Mail\MailLayout::companyName() }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
