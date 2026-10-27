<x-admin-mail :message="$message ?? null">
# {{ __(':client asked to change their details', ['client' => $request->client->name]) }}

{{ __('From the client area. Nothing is saved until you approve it on the client page.') }}

<x-mail::table>
| {{ __('Field') }} | {{ __('Now') }} | {{ __('Asked for') }} |
| :-- | :-- | :-- |
@foreach ($rows as [$label, $old, $new])
| {{ $label }} | {{ $old }} | **{{ $new }}** |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
{{ __('Review the request') }}
</x-mail::button>
</x-admin-mail>
