{{-- Flat theme: the shared layout (filament.themes.layout) in the logo's green with Filament's grey and Inter, flat:
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

    /* Filled primary buttons in the logo's green with white text (Filament would pick its pale 400 shade with dark
       text in light mode); dark mode already does this */
    html.fi:not(.dark) .fi-btn.fi-color-primary:not(.fi-outlined),
    html.fi:not(.dark) :checked + label.fi-btn.fi-color-primary:not(.fi-outlined) {
        --bg: var(--primary-600) !important;
        --hover-bg: var(--primary-700) !important;
        --text: var(--color-white) !important;
        --hover-text: var(--color-white) !important;
        background-color: var(--primary-600);
        color: var(--color-white);
    }

    html.fi:not(.dark) .fi-btn.fi-color-primary:not(.fi-outlined):hover {
        background-color: var(--primary-700);
    }

    html.fi:not(.dark) .fi-btn.fi-color-primary:not(.fi-outlined) > .fi-icon,
    html.fi:not(.dark) .fi-btn.fi-color-primary:not(.fi-outlined) .fi-btn-label {
        color: var(--color-white);
    }

    /* The current menu item and tab: Filament writes them in primary 600 on a pale green, 3.9:1; 700 passes AA */
    html.fi:not(.dark) .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > :is(.fi-icon, .fi-sidebar-item-label),
    html.fi:not(.dark) .fi-tabs-item.fi-active :is(.fi-icon, .fi-tabs-item-label) {
        color: var(--primary-700) !important;
    }
</style>
