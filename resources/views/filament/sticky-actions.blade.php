{{--
    Form buttons that stay at the foot of the screen while a long form scrolls (BasePage::stickyFormActions() in
    AppServiceProvider: every resource's create and edit page and the profile pages, both panels; plus
    EditEmailTemplate). Filament floats them only once they scroll out of view; this gives that floating bar the
    look of the Settings pages' Save bar (filament/settings/styles), on the right with Save rightmost like it. Both panels, PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-sc-actions.fi-sticky .fi-ac {
        bottom: 1rem;
        margin-inline: 0; /* in line with the form's cards, as on the Settings pages */
        padding: .7rem .8rem;
        border-radius: var(--theme-radius-control, .75rem);
        background: color-mix(in oklab, var(--theme-card, var(--color-white)) 85%, transparent);
        box-shadow: 0 0 0 1px var(--theme-line, var(--gray-200)), 0 18px 40px -20px rgba(0, 0, 0, .35);
        backdrop-filter: blur(14px) saturate(1.3);
        -webkit-backdrop-filter: blur(14px) saturate(1.3);
    }

    .dark .fi-sc-actions.fi-sticky .fi-ac {
        background: color-mix(in oklab, var(--theme-card, var(--gray-900)) 85%, transparent);
        box-shadow: 0 0 0 1px var(--theme-line, color-mix(in oklab, var(--color-white) 10%, transparent)), 0 18px 40px -20px rgba(0, 0, 0, .6);
    }

    /* On a phone the buttons stay on one row, scrolling sideways if they must, and on the right with Save rightmost
       (BasePage::alignFormActionsEnd(); Filament lines them up from the end only on wider screens) */
    @media (max-width: 639px) {
        .fi-sc-actions.fi-sticky .fi-ac {
            flex-wrap: nowrap;
            overflow-x: auto;
        }

        .fi-sc-actions .fi-ac.fi-align-end:not(.fi-width-full) {
            flex-direction: row-reverse;
        }
    }
</style>
