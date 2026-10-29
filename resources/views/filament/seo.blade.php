{{-- What search engines and link previews read (App\Support\Seo): in the client area's head, and noindex in /admin. --}}
@if (filament()->getId() !== 'client')
    <meta name="robots" content="noindex, nofollow">
@else
    @php
        $description = \App\Support\Seo::description();
        $logo = \App\Support\Branding::url('logo');
        $name = config('gaiacp.company.name') ?: 'GaiaCP';
        $indexable = \App\Support\Seo::indexable();
    @endphp
    @if ($indexable)
        <link rel="canonical" href="{{ \App\Support\Seo::canonical() }}">
        @foreach (\App\Support\Seo::alternates() as $hreflang => $href)
            <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
        @endforeach
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if ($description)
        <meta name="description" content="{{ $description }}">
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="{{ \App\Support\Seo::title() }}">
    <meta property="og:url" content="{{ $indexable ? \App\Support\Seo::canonical() : url()->current() }}">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    @if ($logo)
        <meta property="og:image" content="{{ $logo }}">
    @endif
@endif
