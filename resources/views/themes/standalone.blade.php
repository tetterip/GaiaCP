{{-- The client area's theme (Settings → Client area) for pages outside Filament: its font from Bunny Fonts and its colours
     as the --bg, --card, --line, --text, --muted, --accent and --radius those pages use. Nothing with no theme on, so
     each page keeps its own values. @include it in the <head> after the page's own <style>. --}}
@php
    $theme = \App\Themes\Themes::forClientArea();
    $font = $theme?->themeFont();
    $colors = $theme?->themeStandaloneColors();
    $vars = fn (array $values) => collect($values)->map(fn (string $value, string $name) => "--{$name}: {$value};")->join(' ');
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
@if ($brand = \App\Themes\Themes::brandColor())
    {{-- Settings → Client area → Brand colour --}}
    <style>:root { --accent: {{ $brand }}; }</style>
@endif
