{{-- Every client email: the layout from Settings → Mail around the template's text. Not indented: Markdown. --}}
<x-client-mail :message="$message ?? null">
@if (filled($header))
{!! $header !!}

@endif
{!! $body !!}
@if (filled($clientAreaUrl ?? null))

<x-mail::button :url="$clientAreaUrl">
{{ ($linkLabel ?? null) ?: __('View in the client area') }}
</x-mail::button>
@endif
@if (filled($footer))

{!! $footer !!}
@endif
</x-client-mail>
