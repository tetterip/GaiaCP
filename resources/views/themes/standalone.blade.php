{{-- The client area's theme (Settings → Client area) for pages outside Filament: its font from Bunny Fonts and its colours
     as the --bg, --card, --line, --text, --muted, --accent and --radius those pages use. Nothing with no theme on, so
     each page keeps its own values. Also --link (the accent made readable on the card) and --on-accent (the text of a
     filled button), worked out per colour scheme. @include it in the <head> after the page's own <style>. --}}
@php
    $theme = \App\Themes\Themes::forClientArea();
    $font = $theme?->themeFont();
    $colors = $theme?->themeStandaloneColors();
    $vars = fn (array $values) => collect($values)->map(fn (string $value, string $name) => "--{$name}: {$value};")->join(' ');
    $brand = \App\Themes\Themes::brandColor();
    // what the accent sits on: the theme's cards, else client-area/page.blade.php's own
    $cards = ['light' => $colors['light']['card'] ?? '#ffffff', 'dark' => $colors['dark']['card'] ?? '#1b201d'];
    $accents = $brand ? ['light' => $brand, 'dark' => $brand] : array_filter(['light' => $colors['light']['accent'] ?? null, 'dark' => $colors['dark']['accent'] ?? null]);
    $derived = collect($accents)->map(fn (string $accent, string $mode) => $vars([
        'link' => \App\Themes\Themes::readableOn($accent, $cards[$mode]),
        'on-accent' => \App\Themes\Themes::textOn($accent),
    ]));
@endphp
@if ($theme)
    @if (filled($font))
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family={{ \Illuminate\Support\Str::kebab(strtolower($font)) }}:400,500,600,700&display=swap">
    @endif
    <style>
        :root { {{ $vars($colors['light']) }} --radius: {{ $colors['radius'] }}; }
        @media (prefers-color-scheme: dark) {
            :root { {{ $vars($colors['dark']) }} }
        }
        @if (filled($font))
            body { font-family: '{{ $font }}', system-ui, -apple-system, "Segoe UI", sans-serif; }
        @endif
    </style>
@endif
@if ($brand)
    {{-- Settings → Client area → Brand colour --}}
    <style>:root { --accent: {{ $brand }}; }</style>
@endif
@if ($derived->isNotEmpty())
    <style>
        :root { {{ $derived['light'] ?? '' }} }
        @media (prefers-color-scheme: dark) {
            :root { {{ $derived['dark'] ?? '' }} }
        }
    </style>
@endif
