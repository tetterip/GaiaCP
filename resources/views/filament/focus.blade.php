{{--
    A keyboard focus mark every theme keeps (WCAG 2.4.7): a 2px outline in the primary colour around whatever Tab
    reaches. An outline, not a box-shadow, so a theme's own shadows (Glass gives buttons a box-shadow with !important,
    which hid Filament's focus ring) never cover it. Only :focus-visible, so a mouse click shows nothing. Text fields
    keep Filament's own mark on their frame. Also: a bigger target for the select's clear cross, borders in Windows
    contrast themes. Both panels and every theme, PanelsRenderHook::HEAD_END, after the theme's styles.
--}}
<style>
    :where(a, button, summary, [role='button'], [role='tab'], [role='switch'], [role='menuitem'], [tabindex]:not([tabindex='-1']), input[type='checkbox'], input[type='radio']):focus-visible {
        outline: 2px solid var(--primary-600) !important;
        outline-offset: 2px !important;
    }

    .dark :where(a, button, summary, [role='button'], [role='tab'], [role='switch'], [role='menuitem'], [tabindex]:not([tabindex='-1']), input[type='checkbox'], input[type='radio']):focus-visible {
        outline-color: var(--primary-400) !important;
    }

    /* Inside a box that clips its edges (side menu, tabs, table rows, menus) the mark goes just inside */
    :is(.fi-sidebar-item-btn, .fi-sidebar-group-btn, .fi-tabs-item, .fi-ta-col, .fi-dropdown-list-item, .fi-ta-record, .fi-ta-cell a):focus-visible {
        outline-offset: -2px !important;
    }

    /* Filament's 16px "Clear selection" cross in selects: a 24px target (WCAG 2.5.8) that takes no more room */
    .fi-select-input-value-remove-btn {
        padding: 4px;
        margin: -4px;
        box-sizing: content-box;
    }

    /* Windows contrast themes drop every shadow, and the themes draw the edges of buttons, fields and cards with
       shadows: give them a real border there, and the focus mark the system's own colour (WCAG 1.4.11) */
    @media (forced-colors: active) {
        :focus-visible {
            outline-color: Highlight !important;
        }

        .fi-btn, .fi-icon-btn, .fi-input-wrp, .fi-section, .fi-ta-ctn, .fi-dropdown-panel, .fi-modal-window {
            border: 1px solid CanvasText !important;
        }
    }
</style>
