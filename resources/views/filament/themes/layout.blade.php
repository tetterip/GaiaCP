{{--
    The layout every theme shares, in both panels (the redesign): a sidebar and top bar on the page colour around an
    inset main area, grouped menu with quiet labels and count badges, the admin's card at the sidebar's foot (admin
    markup only, like the top bar's Client area / New order), cards with a hairline. The client area's own views
    are styled by filament.themes.client. A theme's stylesheet @includes it after setting these on html.fi (and html.fi.dark):
    --theme-side, --theme-main, --theme-card, --theme-line, --theme-ink, --theme-muted, --theme-soft,
    --theme-accent, --theme-shadow, --theme-shadow-hover, --theme-radius (cards), --theme-radius-control,
    --theme-radius-inset.
--}}
<style>
    /* Shell: the sidebar and top bar on the page colour around the inset main area */
    .fi-body {
        background: var(--theme-side);
        color: var(--theme-ink);
    }

    .fi-topbar,
    .fi-sidebar,
    .fi-sidebar-header {
        background: var(--theme-side) !important;
        box-shadow: none !important;
    }

    .fi-topbar {
        border-bottom: 0;
    }

    .fi-main-ctn {
        background: var(--theme-main);
    }

    @media (min-width: 1024px) {
        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-main-ctn {
            box-shadow: inset 1px 0 0 var(--theme-line);
        }

        /* The logo sits over the sidebar; search and actions open the inset area, which curves at its top corner */
        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-topbar {
            padding-inline-end: 0;
            align-items: stretch;
        }

        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-topbar-start {
            width: calc(var(--sidebar-width, 20rem) - 1rem);
            margin-inline-end: 0;
            flex-shrink: 0;
        }

        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-topbar-end {
            flex: 1;
            margin-top: .75rem;
            padding: .75rem 2rem .5rem;
            background: var(--theme-main);
            border-top-left-radius: var(--theme-radius-inset);
            box-shadow: inset 1px 1px 0 var(--theme-line);
        }
    }

    /* Top bar: a wide search with its shortcut, then Client area and New order */
    .fi-topbar-end {
        column-gap: .75rem;
    }

    .fi-topbar .fi-global-search-ctn {
        flex: 1;
        max-width: 520px;
    }

    .fi-topbar .fi-global-search-field .fi-input-wrp {
        min-height: 2.75rem;
        background: var(--theme-card);
        box-shadow: 0 0 0 1px var(--theme-line);
        border-radius: var(--theme-radius-control) !important;
    }

    /* The field is taller than Filament's, so the input fills it and its text sits in the middle */
    .fi-topbar .fi-global-search-field .fi-input-wrp-content-ctn {
        display: flex;
    }

    .fi-topbar .fi-global-search-field .fi-input {
        height: auto;
        align-self: stretch;
    }

    .fi-topbar-actions {
        display: flex;
        align-items: center;
        gap: .5rem;
        margin-inline-start: auto;
    }

    .fi-topbar-action {
        min-height: 2.75rem;
        padding-inline: 1rem;
    }

    .fi-topbar-client-area {
        background: var(--theme-card);
    }

    /* The bell after them (and the client area's cart), the same height as the buttons, its count on a filled dot */
    .fi-topbar .fi-topbar-database-notifications-btn,
    .fi-topbar .gcp-cart-icon {
        position: relative;
        width: 2.75rem;
        height: 2.75rem;
        background: var(--theme-card);
        box-shadow: 0 0 0 1px var(--theme-line);
        border-radius: var(--theme-radius-control);
    }

    .fi-topbar .gcp-cart-icon {
        margin-inline-end: .5rem;
    }

    /* Inside the button, in its top end corner */
    .fi-topbar .fi-topbar-database-notifications-btn .fi-icon-btn-badge-ctn,
    .fi-topbar .gcp-cart-icon .fi-icon-btn-badge-ctn {
        top: .2rem;
        inset-inline-start: auto;
        inset-inline-end: .2rem;
        width: auto;
        translate: none; /* Filament centres it on the corner with Tailwind's translate */
        background: transparent;
    }

    .fi-topbar .fi-topbar-database-notifications-btn .fi-badge,
    .fi-topbar .gcp-cart-icon .fi-badge {
        background: var(--primary-600);
        color: #fff;
        box-shadow: none;
        min-width: 1.15rem;
        padding-inline: .3rem;
        justify-content: center;
    }

    @media (max-width: 767px) {
        .fi-topbar-action {
            padding-inline: .75rem;
        }

        .fi-topbar-action-label {
            display: none;
        }

        .fi-topbar .fi-global-search-field .fi-input-wrp-suffix {
            display: none;
        }
    }

    /* Menu: quiet group labels, the current page on a tile with an accent icon */
    .fi-sidebar-nav,
    .fi-sidebar-nav-groups {
        row-gap: 1.25rem;
    }

    .fi-sidebar-group-btn {
        padding-block: .25rem;
    }

    .fi-sidebar-group-label {
        color: var(--theme-muted);
        font-size: .75rem;
        font-weight: 500;
    }

    .fi-sidebar-item-btn {
        border-radius: var(--theme-radius-control);
        padding-block: .55rem;
        transition: background-color .18s ease;
    }

    .fi-sidebar-item-btn:hover {
        background: var(--theme-soft) !important;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background: var(--theme-main) !important;
        box-shadow: inset 0 0 0 1px var(--theme-line);
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: var(--theme-accent) !important;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-sidebar-item-label {
        color: var(--theme-ink) !important;
        font-weight: 600;
    }

    .fi-sidebar-item .fi-badge {
        box-shadow: none !important;
        min-width: 1.6rem;
        justify-content: center;
        font-weight: 600;
    }

    /* The admin's own card at the foot of the sidebar */
    .fi-sidebar-footer {
        border-top: 0;
        margin-inline: .75rem;
        padding-top: .5rem;
    }

    .fi-sidebar .fi-user-menu-trigger {
        padding: .6rem .75rem;
        border-radius: var(--theme-radius-control);
        background: var(--theme-main);
        box-shadow: inset 0 0 0 1px var(--theme-line);
        font-weight: 600;
        justify-content: flex-start;
    }

    .fi-sidebar .fi-user-menu-trigger:hover {
        background: var(--theme-soft);
    }

    .fi-sidebar .fi-user-menu-trigger .fi-user-menu-trigger-text {
        flex: 1;
        text-align: start;
    }

    .fi-sidebar .fi-user-menu-trigger .fi-avatar,
    .fi-topbar .fi-user-menu-trigger .fi-avatar {
        border-radius: calc(var(--theme-radius-control) - 2px);
        width: 2.25rem;
        height: 2.25rem;
    }

    /* The menu on a phone: a drawer with rounded corners */
    @media (max-width: 1023px) {
        .fi-sidebar.fi-sidebar-open {
            border-radius: 0 var(--theme-radius-inset) var(--theme-radius-inset) 0;
            box-shadow: 0 20px 50px -20px rgba(0, 0, 0, .45) !important;
        }
    }

    /* Cards: a hairline, the theme's radius and shadow */
    .fi-section:not(.fi-section-not-contained),
    .fi-ta-ctn,
    .fi-wi-stats-overview-stat,
    .fi-fo-repeater-item,
    .fi-in-repeatable-item {
        background: var(--theme-card) !important;
        border-radius: var(--theme-radius) !important;
        box-shadow: var(--theme-shadow), 0 0 0 1px var(--theme-line) !important;
    }

    .fi-section:not(.fi-section-not-contained) .fi-section:not(.fi-section-not-contained) {
        border-radius: calc(var(--theme-radius) - 6px) !important;
        box-shadow: 0 0 0 1px var(--theme-line) !important;
    }

    .fi-ta-ctn {
        overflow: hidden;
    }

    .fi-simple-layout {
        background: var(--theme-main);
    }

    .fi-simple-main {
        border-radius: var(--theme-radius);
        box-shadow: var(--theme-shadow), 0 0 0 1px var(--theme-line) !important;
    }

    .fi-modal-window {
        border-radius: var(--theme-radius) !important;
    }

    .fi-dropdown-panel {
        border-radius: var(--theme-radius-control) !important;
        box-shadow: var(--theme-shadow-hover), 0 0 0 1px var(--theme-line) !important;
    }

    /* Controls */
    .fi-btn {
        border-radius: var(--theme-radius-control);
    }

    .fi-input-wrp,
    .fi-select-input,
    .fi-global-search-field .fi-input-wrp {
        border-radius: var(--theme-radius-control) !important;
    }

    .fi-badge {
        border-radius: 999px;
    }

    .fi-tabs {
        border-radius: calc(var(--theme-radius-control) + 2px);
    }

    .fi-tabs-item {
        border-radius: calc(var(--theme-radius-control) - 2px);
    }

    /* A list's tabs: underlined, with their counts, on a line across the page */
    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) {
        margin-inline: 0;
        padding: 0;
        gap: 1.75rem;
        background: transparent;
        border-radius: 0;
        box-shadow: inset 0 -1px 0 var(--theme-line);
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item {
        min-height: 2.75rem;
        padding: 0 .25rem;
        border-radius: 0;
        background: transparent !important;
        border-bottom: 2px solid transparent;
        font-size: .95rem;
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active {
        border-color: var(--theme-accent);
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active .fi-tabs-item-label {
        color: var(--theme-ink) !important;
        font-weight: 600;
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item .fi-badge {
        box-shadow: none;
        background: var(--theme-soft);
        color: var(--theme-muted);
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active .fi-badge {
        background: color-mix(in oklab, var(--theme-accent) 14%, transparent);
        color: var(--theme-accent);
    }

    /* A table's search wide enough for its hint */
    @media (min-width: 640px) {
        .fi-ta-search-field {
            width: 20rem;
        }
    }

    /* A cluster's sub-navigation (Settings, Reports): a card of plain links under their group names */
    @media (min-width: 768px) {
        .fi-page-sub-navigation-sidebar-ctn {
            width: 15rem;
            flex-shrink: 0;
        }
    }

    .fi-page-sub-navigation-sidebar {
        row-gap: 1rem;
        padding: .9rem .6rem;
        background: var(--theme-card);
        border-radius: var(--theme-radius);
        box-shadow: var(--theme-shadow), 0 0 0 1px var(--theme-line);
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-group-label {
        color: var(--theme-ink);
        font-weight: 600;
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-item-btn {
        padding-block: .45rem;
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-item-btn > .fi-icon {
        display: none;
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-item-label {
        color: var(--theme-muted);
        font-weight: 400;
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background: color-mix(in oklab, var(--theme-accent) 12%, transparent) !important;
        box-shadow: none;
    }

    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-sidebar-item-label {
        color: var(--theme-accent) !important;
    }

    /* A page's breadcrumb reads as a quiet line over its title */
    .fi-header .fi-breadcrumbs {
        font-size: .8rem;
    }

    .fi :focus-visible {
        outline-color: var(--theme-accent);
    }
</style>
