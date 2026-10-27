<x-admin-mail :message="$message ?? null">
# {{ __('New order #:number from :client', ['number' => $order->id, 'client' => $order->client->name]) }}

{{ __('Placed in the client area. Its payment notice went to the client; items are set up as their Auto setup says.') }}

<x-mail::table>
| {{ __('Item') }} | {{ __('Billing cycle') }} | {{ __('First payment') }} |
| :-- | :-- | --: |
@foreach ($rows as [$item, $cycle, $amount])
| {{ $item }} | {{ $cycle }} | {{ $amount }} |
@endforeach
| **{{ __('Total') }}** | | **{{ $total }}** |
</x-mail::table>

@if (filled($order->notes))
{{ __('Notes') }}: {{ $order->notes }}
@endif

<x-mail::button :url="$url">
{{ __('Open the order') }}
</x-mail::button>
</x-admin-mail>
