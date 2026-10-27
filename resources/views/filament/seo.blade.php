{{-- What search engines and link previews read (App\Support\Seo): in the client area's head, and noindex in /admin. --}}
@if (filament()->getId() !== 'client')
    <meta name="robots" content="noindex, nofollow">
@else
    @php
        $description = \App\Support\Seo::description();
        $logo = \App\Support\Branding::url('logo');
        $name = config('gaiacp.company.name') ?: 'GaiaCP';
    @endphp
    @unless (\App\Support\Seo::indexable())
        <meta name="robots" content="noindex, nofollow">
    @endunless
    @if ($description)
        <meta name="description" content="{{ $description }}">
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="{{ $name }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($logo)
        <meta property="og:image" content="{{ $logo }}">
    @endif
@endif
