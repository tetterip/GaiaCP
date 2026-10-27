{{-- Flat theme: the shared layout (filament.themes.layout) in Filament's own colours (emerald, grey, Inter), flat:
     no shadows, small radii, no motion. Plain CSS over Filament's stylesheet (STYLES_AFTER), nothing to build --}}
@include('filament.themes.layout')
<style>
    html.fi {
        --theme-side: var(--color-white);
        --theme-main: var(--gray-50);
        --theme-card: var(--color-white);
        --theme-line: var(--gray-200);
        --theme-ink: var(--gray-950);
        --theme-muted: var(--gray-500);
        --theme-soft: var(--gray-100);
        --theme-accent: var(--primary-600);
        --theme-shadow: 0 0 #0000;
        --theme-shadow-hover: 0 0 #0000;
        --theme-radius: 12px;
        --theme-radius-control: 8px;
        --theme-radius-inset: 16px;
    }

    html.fi.dark {
        --theme-side: var(--gray-900);
        --theme-main: var(--gray-950);
        --theme-card: var(--gray-900);
        --theme-line: color-mix(in oklab, var(--color-white) 10%, transparent);
        --theme-ink: var(--color-white);
        --theme-muted: var(--gray-400);
        --theme-soft: color-mix(in oklab, var(--color-white) 5%, transparent);
        --theme-accent: var(--primary-400);
    }
</style>
