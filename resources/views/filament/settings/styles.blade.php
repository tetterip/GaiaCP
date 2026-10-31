{{-- A settings page's Save bar (SettingsFormPage): kept at the foot of the screen while the form scrolls, with a
     note and Cancel once a field changes. In Filament's colours with the theme's where it has them. --}}
@once
<style>
    /* Sticky on the form's footer, which runs the whole form's height */
    .fi-sc-form > .fi-grid:has(.gx-save-bar) {
        position: sticky;
        bottom: 1rem;
        z-index: 20;
    }

    /* An open dropdown (a Builder's Add to the menu, say) stays above the Save bar: its part of the form rises over it */
    .fi-sc-form > .fi-grid:not(:has(.gx-save-bar)):has(.fi-dropdown-panel:not([style*="display: none"])) {
        position: relative;
        z-index: 21;
    }

    .gx-save-bar {
        align-items: center;
        padding: .7rem .8rem .7rem 1.25rem;
        border-radius: var(--theme-radius-control, .75rem);
        background: color-mix(in oklab, var(--theme-card, var(--color-white)) 85%, transparent);
        box-shadow: 0 0 0 1px var(--theme-line, var(--gray-200)), 0 18px 40px -20px rgba(0, 0, 0, .35);
        backdrop-filter: blur(14px) saturate(1.3);
        -webkit-backdrop-filter: blur(14px) saturate(1.3);
    }

    .dark .gx-save-bar {
        background: color-mix(in oklab, var(--theme-card, var(--gray-900)) 85%, transparent);
        box-shadow: 0 0 0 1px var(--theme-line, color-mix(in oklab, var(--color-white) 10%, transparent)), 0 18px 40px -20px rgba(0, 0, 0, .6);
    }

    .gx-save-bar-note {
        display: inline-flex;
        align-items: flex-start;
        gap: .6rem;
    }

    /* A round dot beside the first line, however many lines the note takes */
    .gx-save-bar-note::before {
        content: '';
        flex: none;
        width: .5rem;
        height: .5rem;
        margin-top: calc((1lh - .5rem) / 2);
        border-radius: 50%;
        background: var(--warning-500);
    }

    /* The buttons keep their width and stay side by side; the note takes what is left */
    .gx-save-bar.fi-sc-flex {
        align-items: center;
    }

    .gx-save-bar > .fi-growable:has(.fi-sc-actions) {
        flex: none;
        width: auto;
    }

    .gx-save-bar .fi-ac {
        flex-wrap: nowrap;
    }

    /* On a phone the note takes its own line over the buttons */
    @media (max-width: 639px) {
        .gx-save-bar.fi-sc-flex {
            flex-wrap: wrap;
            gap: .6rem;
            padding: .7rem .7rem .7rem 1rem;
        }

        .gx-save-bar > .fi-growable:not(:has(.fi-sc-actions)) {
            flex: 1 0 100%;
        }

        .gx-save-bar > .fi-growable:not(:has(.fi-sc-actions)):has(.gx-save-bar-note[style*="display: none"]) {
            display: none;
        }

        .gx-save-bar > .fi-growable:has(.fi-sc-actions) {
            margin-inline-start: auto;
        }
    }
</style>
@endonce
