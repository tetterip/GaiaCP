{{--
    A small chevron after the label of every button that opens a menu (an ActionGroup shown as a button or a link,
    e.g. a client's Client area / Credit / Suggestions, a service's Account and Price, the visitors' language), so it
    reads as "has more choices" rather than doing something at once. Icon buttons, the ⋮ menus and the visitors'
    icon-only theme button are left alone. Menus also grow to fit their longest choice. Both panels,
    PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-dropdown-trigger > .fi-btn:not(.gcp-public-theme-btn)::after,
    .fi-dropdown-trigger > .fi-link::after {
        content: '';
        flex: none;
        width: .9rem;
        height: .9rem;
        margin-inline-start: -.15rem;
        margin-inline-end: -.2rem;
        background-color: currentColor;
        opacity: .6;
        -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath fill-rule='evenodd' d='M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z'/%3E%3C/svg%3E") center / contain no-repeat;
        mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath fill-rule='evenodd' d='M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z'/%3E%3C/svg%3E") center / contain no-repeat;
    }

    /*
     * Menus that set no width of their own grow with their longest choice: Filament keeps them at 14rem and cuts the
     * text, which hides most of a Greek label. Its 14rem is an !important max-width in a cascade layer, which an
     * unlayered rule can't override, but a larger min-width wins over a max-width. Each choice is capped instead, so
     * a very long one wraps and the menu stays on a phone's screen.
     */
    .fi-dropdown-panel:not([class*="fi-width-"]):has(.fi-dropdown-list-item) {
        min-width: max-content;
    }

    .fi-dropdown-panel .fi-dropdown-list-item-label {
        max-width: min(18rem, calc(100vw - 7rem));
        white-space: normal;
        overflow-wrap: anywhere;
    }
</style>
