{{-- Gaia theme: the shared layout (filament.themes.layout) in its colours, plus Literata headings, the dark teal
     primary button and quiet motion. Laid over Filament's own stylesheet (STYLES_AFTER); plain CSS, nothing to build --}}
@include('filament.themes.layout')
<style>
    html.fi {
        --gaia-side: #ffffff;
        --gaia-main: #f4f4f5;
        --gaia-card: #ffffff;
        --gaia-line: #e4e4e7;
        --gaia-ink: #18181b;
        --gaia-muted: #5b5b63;
        --gaia-soft: #f0f0f2;
        --gaia-accent: #23696b;
        --gaia-button: #16302c;
        --gaia-button-ink: #ffffff;
        --gaia-shadow: 0 1px 2px rgba(24, 24, 27, .04), 0 10px 28px -14px rgba(24, 24, 27, .12);
        --gaia-shadow-hover: 0 1px 2px rgba(0, 0, 0, .04), 0 16px 34px -18px rgba(0, 0, 0, .22);
        --gaia-ease: cubic-bezier(.2, .7, .2, 1);
        --theme-radius: 22px;
        --theme-radius-control: 12px;
        --theme-radius-inset: 28px;
    }

    html.fi,
    html.fi.dark {
        --theme-side: var(--gaia-side);
        --theme-main: var(--gaia-main);
        --theme-card: var(--gaia-card);
        --theme-line: var(--gaia-line);
        --theme-ink: var(--gaia-ink);
        --theme-muted: var(--gaia-muted);
        --theme-soft: var(--gaia-soft);
        --theme-accent: var(--gaia-accent);
        --theme-shadow: var(--gaia-shadow);
        --theme-shadow-hover: var(--gaia-shadow-hover);
    }

    html.fi.dark {
        --gaia-side: #141415;
        --gaia-main: #0f0f10;
        --gaia-card: #1a1a1c;
        --gaia-line: #2a2a2e;
        --gaia-ink: #ededef;
        --gaia-muted: #a1a1a8;
        --gaia-soft: #232326;
        --gaia-accent: #72cbb8;
        --gaia-button: #77bbbc;
        --gaia-button-ink: #111113;
        --gaia-shadow: 0 1px 0 rgba(255, 255, 255, .02), 0 12px 30px -16px rgba(0, 0, 0, .6);
        --gaia-shadow-hover: 0 1px 0 rgba(255, 255, 255, .03), 0 18px 36px -18px rgba(0, 0, 0, .75);
    }

    /* Quiet motion: icons nudge on hover */
    .fi-sidebar-item-btn > .fi-icon {
        transition: transform .2s ease, color .2s ease;
    }

    .fi-sidebar-item-btn:hover > .fi-icon {
        transform: translateX(2px);
    }

    /* Headings in Literata */
    .fi-header-heading,
    .fi-simple-header-heading,
    .fi-modal-heading {
        font-family: var(--serif-font-family), Georgia, serif;
        font-weight: 500;
        letter-spacing: -.01em;
    }

    .fi-section-header-heading,
    .fi-ta-header-heading,
    .fi-wi-stats-overview-stat-value,
    .gx-big,
    .gx-mid,
    .gx-title {
        font-family: var(--serif-font-family), Georgia, serif;
        font-weight: 500;
    }

    .fi-wi-stats-overview-stat {
        transition: transform .25s var(--gaia-ease), box-shadow .25s ease;
    }

    .fi-wi-stats-overview-stat:hover {
        transform: translateY(-2px);
        box-shadow: var(--gaia-shadow-hover), 0 0 0 1px var(--gaia-line) !important;
    }

    .fi-ta-row {
        transition: background-color .15s ease;
    }

    /* Controls: rounder buttons and fields, a dark primary button, a small press */
    .fi-btn {
        transition: transform .15s ease, background-color .15s ease, filter .15s ease;
    }

    .fi-btn:active {
        transform: scale(.97);
    }

    .fi-btn.fi-color-primary:not(.fi-outlined) {
        background: var(--gaia-button) !important;
        color: var(--gaia-button-ink) !important;
    }

    .fi-btn.fi-color-primary:not(.fi-outlined) > .fi-icon,
    .fi-btn.fi-color-primary:not(.fi-outlined) .fi-btn-label {
        color: var(--gaia-button-ink) !important;
    }

    .fi-btn.fi-color-primary:not(.fi-outlined):hover {
        filter: brightness(1.12);
    }

    /* A chosen toggle button (e.g. Stripe's Live) in the theme's dark teal, not Filament's bright green */
    :checked + label.fi-btn:not(.fi-outlined):is(.fi-color-primary, .fi-color-success) {
        background: var(--gaia-button) !important;
        color: var(--gaia-button-ink) !important;
    }

    :checked + label.fi-btn:not(.fi-outlined):is(.fi-color-primary, .fi-color-success) > .fi-icon {
        color: var(--gaia-button-ink) !important;
    }

    .fi-tabs-item {
        transition: background-color .2s ease, color .2s ease;
    }

    .fi-tabs-item.fi-active {
        background: var(--gaia-button) !important;
    }

    .fi-tabs-item.fi-active .fi-tabs-item-label,
    .fi-tabs-item.fi-active > .fi-icon {
        color: var(--gaia-button-ink) !important;
    }

    .fi-tabs-item.fi-active .fi-badge {
        background: color-mix(in oklab, var(--gaia-button-ink) 18%, transparent);
        color: var(--gaia-button-ink);
    }

    /* A short rise as a page opens */
    @keyframes gaia-rise {
        from {
            opacity: 0;
            transform: translateY(8px);
        }
    }

    .fi-page > section,
    .fi-simple-main {
        animation: gaia-rise .45s var(--gaia-ease) both;
    }

    @media (prefers-reduced-motion: reduce) {
        .fi *,
        .fi *::before,
        .fi *::after {
            animation: none !important;
            transition: none !important;
        }
    }
</style>
