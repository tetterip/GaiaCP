{{-- Glass theme: the shared layout (filament.themes.layout) as frosted glass over a soft background in the logo's teal
     and greens: see-through blurred cards, a floating sidebar, glass menus, windows and pill buttons, Manrope. Plain CSS
     over Filament's stylesheet (STYLES_AFTER), nothing to build.
     Cards blur through a ::before layer, never with backdrop-filter on the card itself: that would make the card the
     containing block of position: fixed and trap the modals opened from inside it (see GaiaTheme 1.3.2). --}}
@include('filament.themes.layout')
<style>
    html.fi {
        --glass-bg: #e9f1f1;
        --glass-orb-1: rgba(72, 163, 146, .55);
        --glass-orb-2: rgba(53, 140, 140, .45);
        --glass-orb-3: rgba(61, 152, 107, .35);
        --glass-orb-4: rgba(140, 196, 214, .45);
        --glass-pane: rgba(255, 255, 255, .58);
        --glass-pane-strong: rgba(255, 255, 255, .8);
        --glass-pane-soft: rgba(255, 255, 255, .38);
        --glass-edge: rgba(255, 255, 255, .75);
        --glass-line: rgba(15, 40, 45, .09);
        --glass-shine: rgba(255, 255, 255, .7);
        --glass-ink: #0f1c1f;
        --glass-muted: #56666a;
        --glass-accent: #1f6e70;
        --glass-bar: rgba(240, 246, 246, .82);
        --glass-ok: #7fcfae;
        --glass-blur: blur(18px) saturate(170%);
        --glass-drop: 0 1px 1px rgba(15, 40, 45, .04), 0 18px 40px -22px rgba(15, 40, 45, .28);
        --glass-drop-hover: 0 1px 1px rgba(15, 40, 45, .05), 0 22px 46px -20px rgba(15, 40, 45, .36);
        --glass-ease: cubic-bezier(.2, .8, .2, 1);

        --theme-side: transparent;
        --theme-main: var(--glass-pane-soft);
        --theme-card: var(--glass-pane);
        --theme-line: var(--glass-line);
        --theme-ink: var(--glass-ink);
        --theme-muted: var(--glass-muted);
        --theme-soft: rgba(255, 255, 255, .5);
        --theme-accent: var(--glass-accent);
        --theme-shadow: var(--glass-drop);
        --theme-shadow-hover: var(--glass-drop-hover);
        --theme-radius: 24px;
        --theme-radius-control: 14px;
        --theme-radius-inset: 28px;
    }

    html.fi.dark {
        --glass-bg: #070b0d;
        --glass-orb-1: rgba(44, 122, 124, .42);
        --glass-orb-2: rgba(39, 114, 89, .32);
        --glass-orb-3: rgba(53, 140, 140, .22);
        --glass-orb-4: rgba(48, 84, 120, .3);
        --glass-pane: rgba(24, 31, 35, .55);
        --glass-pane-strong: rgba(20, 26, 30, .86);
        --glass-pane-soft: rgba(255, 255, 255, .03);
        --glass-edge: rgba(255, 255, 255, .09);
        --glass-line: rgba(255, 255, 255, .08);
        --glass-shine: rgba(255, 255, 255, .1);
        --glass-ink: #e8eff0;
        --glass-muted: #9aabad;
        --glass-accent: #7cc9c4;
        --glass-bar: rgba(9, 14, 16, .8);
        --glass-ok: #3d9c7b;
        --glass-drop: 0 1px 0 rgba(255, 255, 255, .02), 0 20px 44px -22px rgba(0, 0, 0, .8);
        --glass-drop-hover: 0 1px 0 rgba(255, 255, 255, .03), 0 24px 50px -20px rgba(0, 0, 0, .9);
        --theme-soft: rgba(255, 255, 255, .06);
    }

    /* The background: soft orbs in the logo's colours, fixed behind everything (a layer, so it stays put on phones too) */
    .fi-body {
        background: var(--glass-bg) !important;
    }

    .fi-body::before {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background:
            radial-gradient(38rem 30rem at 8% 6%, var(--glass-orb-1), transparent 70%),
            radial-gradient(34rem 28rem at 92% 14%, var(--glass-orb-4), transparent 70%),
            radial-gradient(40rem 34rem at 78% 96%, var(--glass-orb-2), transparent 70%),
            radial-gradient(30rem 26rem at 22% 88%, var(--glass-orb-3), transparent 70%),
            var(--glass-bg);
    }

    /* The shell floats on it: no grey inset, the top bar's end and the main area clear */
    .fi-main-ctn,
    .fi-simple-layout {
        background: transparent !important;
        box-shadow: none !important;
    }

    @media (min-width: 1024px) {
        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-topbar-end {
            background: transparent;
            box-shadow: none;
        }

        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-main-ctn {
            box-shadow: none !important;
        }
    }

    /* Glass: a blurred ::before behind the element's own see-through colour, a bright top edge and a soft drop (the
       top bar and sidebar are positioned already: sticky and fixed) */
    .fi-section:not(.fi-section-not-contained),
    .fi-ta-ctn,
    .fi-wi-stats-overview-stat,
    .fi-page-sub-navigation-sidebar,
    .fi-simple-main,
    .gx-card,
    .gcp-card {
        position: relative;
    }

    .fi-topbar::before,
    .fi-section:not(.fi-section-not-contained)::before,
    .fi-ta-ctn::before,
    .fi-wi-stats-overview-stat::before,
    .fi-page-sub-navigation-sidebar::before,
    .fi-simple-main::before,
    .gcp-card::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        border-radius: inherit;
        pointer-events: none;
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
    }

    .fi-section:not(.fi-section-not-contained),
    .fi-ta-ctn,
    .fi-wi-stats-overview-stat,
    .fi-fo-repeater-item,
    .fi-in-repeatable-item,
    .fi-page-sub-navigation-sidebar,
    .fi-simple-main {
        box-shadow: var(--theme-shadow), inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge) !important;
    }

    .fi-section:not(.fi-section-not-contained) .fi-section:not(.fi-section-not-contained),
    .fi-section:not(.fi-section-not-contained) .fi-ta-ctn {
        background: var(--glass-pane-soft) !important;
        box-shadow: 0 0 0 1px var(--glass-line) !important;
    }

    .fi-section:not(.fi-section-not-contained) .fi-section:not(.fi-section-not-contained)::before,
    .fi-section:not(.fi-section-not-contained) .fi-ta-ctn::before {
        content: none;
    }

    .fi-simple-main {
        background: var(--glass-pane) !important;
        border-radius: var(--theme-radius);
    }

    /* A table's header and footer rows, and its hover, see-through too */
    .fi-ta-header-ctn,
    .fi-ta-header-toolbar,
    .fi-ta-table > thead > tr,
    .fi-ta-table > thead th,
    .fi-pagination,
    .fi-ta-ctn > div:last-child {
        background-color: transparent !important;
    }

    .fi-ta-table > thead > tr {
        background: var(--theme-soft) !important;
    }

    .fi-ta-row {
        transition: background-color .15s ease;
    }

    .fi-ta-row:hover,
    .fi-ta-row.fi-hover-light:hover {
        background: color-mix(in oklab, var(--glass-accent) 6%, transparent) !important;
    }

    /* The top bar: frosted glass over the page as it scrolls under it */
    .fi-topbar {
        background: transparent !important;
    }

    /* Frosted and mostly opaque, so the page scrolling under it doesn't read through; the colour and the blur fade
       out over a short strip below it instead of ending on a hard line */
    .fi-topbar::before {
        bottom: -1.5rem;
        background: linear-gradient(to bottom, var(--glass-bar) 0, var(--glass-bar) calc(100% - 2.5rem), transparent);
        -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 2.5rem), transparent);
        mask-image: linear-gradient(to bottom, #000 calc(100% - 2.5rem), transparent);
    }

    /* The sidebar: a floating glass panel on wide screens, a frosted drawer on phones */
    .fi-sidebar {
        background: transparent !important;
    }

    .fi-sidebar::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background: var(--glass-pane-soft);
        box-shadow: var(--glass-drop), inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge);
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
    }

    @media (min-width: 1024px) {
        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-sidebar::before {
            inset: 0 .75rem 1rem .5rem;
            border-radius: var(--theme-radius-inset);
        }

        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-sidebar-nav {
            padding-inline: 2rem 1.25rem;
        }

        .fi-body-has-navigation:not(.fi-body-has-top-navigation) .fi-sidebar-footer {
            margin-inline: 1.5rem 1.65rem;
            margin-bottom: 1.75rem;
        }
    }

    @media (max-width: 1023px) {
        .fi-sidebar::before {
            background: var(--glass-pane-strong);
            border-radius: 0 var(--theme-radius-inset) var(--theme-radius-inset) 0;
        }

        .fi-sidebar.fi-sidebar-open {
            border-radius: 0 var(--theme-radius-inset) var(--theme-radius-inset) 0;
        }
    }

    /* Menu: the current page on a bright glass pill with an accent icon */
    .fi-sidebar-item-btn {
        border-radius: 999px;
        transition: background-color .2s ease, box-shadow .2s ease;
    }

    .fi-sidebar-item-btn > .fi-icon {
        transition: transform .25s var(--glass-ease), color .2s ease;
    }

    .fi-sidebar-item-btn:hover > .fi-icon {
        transform: scale(1.08);
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background: var(--glass-pane-strong) !important;
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge), 0 6px 16px -10px rgba(15, 40, 45, .35);
    }

    .fi-sidebar .fi-user-menu-trigger {
        background: var(--glass-pane-soft);
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge);
        border-radius: 999px;
    }

    .fi-sidebar .fi-user-menu-trigger .fi-avatar,
    .fi-topbar .fi-user-menu-trigger .fi-avatar {
        border-radius: 999px;
    }

    /* Menus, windows, notifications: frosted, more opaque so what they hold reads first */
    .fi-dropdown-panel,
    .fi-modal-window,
    .fi-no-notification,
    .fi-sidebar-group-dropdown-trigger-btn + .fi-dropdown-panel {
        background: var(--glass-pane-strong) !important;
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
        box-shadow: var(--glass-drop-hover), inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge) !important;
    }

    .fi-modal-window {
        border-radius: var(--theme-radius) !important;
    }

    .fi-modal-window .fi-modal-header,
    .fi-modal-window .fi-modal-footer,
    .fi-modal-window .fi-modal-content {
        background: transparent !important;
    }

    .fi-modal-close-overlay {
        background: rgba(10, 25, 28, .28) !important;
        -webkit-backdrop-filter: blur(6px);
        backdrop-filter: blur(6px);
    }

    .dark .fi-modal-close-overlay {
        background: rgba(0, 0, 0, .45) !important;
    }

    /* Fields: see-through, brighter as they take focus */
    .fi-input-wrp {
        background: var(--glass-pane-soft) !important;
        box-shadow: inset 0 1px 2px rgba(15, 40, 45, .05), 0 0 0 1px var(--glass-line) !important;
        transition: background-color .15s ease, box-shadow .15s ease;
    }

    .fi-input-wrp:focus-within {
        background: var(--glass-pane-strong) !important;
        box-shadow: 0 0 0 1px var(--glass-accent), 0 0 0 4px color-mix(in oklab, var(--glass-accent) 18%, transparent) !important;
    }

    .fi-topbar .fi-global-search-field .fi-input-wrp {
        background: var(--glass-pane) !important;
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge) !important;
        border-radius: 999px !important;
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
    }

    .fi-input-wrp .fi-input,
    .fi-input-wrp .fi-select-input {
        background: transparent;
    }

    /* Buttons: glass pills. Coloured ones keep their colour, see-through, with a shine on top; the rest are clear glass */
    .fi-btn,
    .fi-topbar-action {
        border-radius: 999px !important;
        transition: transform .15s var(--glass-ease), background-color .2s ease, box-shadow .2s ease, filter .2s ease;
    }

    .fi-btn:not(.fi-outlined) {
        -webkit-backdrop-filter: blur(12px) saturate(170%);
        backdrop-filter: blur(12px) saturate(170%);
        background-image: linear-gradient(180deg, rgba(255, 255, 255, .22), rgba(255, 255, 255, 0) 60%);
    }

    .fi-btn:not(.fi-outlined):not(.fi-color),
    .fi-btn.fi-color-gray:not(.fi-outlined),
    .fi-topbar-client-area {
        background-color: var(--glass-pane) !important;
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge), 0 4px 12px -8px rgba(15, 40, 45, .3) !important;
    }

    .fi-btn:not(.fi-outlined):not(.fi-color):hover,
    .fi-btn.fi-color-gray:not(.fi-outlined):hover {
        background-color: var(--glass-pane-strong) !important;
    }

    /* Coloured buttons in their deep shade with white text (Filament lightens some to keep dark text), warning keeps
       Filament's amber for contrast */
    .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label) {
        background-color: color-mix(in oklab, var(--color-600) 84%, transparent) !important;
        color: #fff !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .38), inset 0 0 0 1px rgba(255, 255, 255, .16), 0 8px 20px -10px var(--color-600) !important;
    }

    .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label) > .fi-icon,
    .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label) .fi-btn-label {
        color: #fff !important;
    }

    .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label):hover {
        background-color: color-mix(in oklab, var(--color-700) 88%, transparent) !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .45), inset 0 0 0 1px rgba(255, 255, 255, .2), 0 12px 26px -12px var(--color-600) !important;
    }

    .dark .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label) {
        background-color: color-mix(in oklab, var(--color-600) 70%, transparent) !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .22), inset 0 0 0 1px rgba(255, 255, 255, .1), 0 8px 22px -10px rgba(0, 0, 0, .7) !important;
    }

    .dark .fi-btn.fi-color:not(.fi-outlined):not(.fi-color-gray):not(.fi-color-warning):not(label):hover {
        background-color: color-mix(in oklab, var(--color-500) 78%, transparent) !important;
    }

    .fi-btn:not(.fi-disabled):not([disabled]):hover,
    .fi-topbar-action:hover {
        transform: translateY(-1px);
    }

    .fi-btn:not(.fi-disabled):not([disabled]):active {
        transform: scale(.97);
    }

    /* A chosen toggle button (e.g. Stripe's Live) and the active tab: a solid accent pill */
    :checked + label.fi-btn:not(.fi-outlined):is(.fi-color-primary, .fi-color-success),
    .fi-tabs-item.fi-active {
        background: color-mix(in oklab, var(--primary-600) 85%, transparent) !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .35), 0 6px 16px -10px var(--primary-600) !important;
    }

    :checked + label.fi-btn:not(.fi-outlined):is(.fi-color-primary, .fi-color-success),
    :checked + label.fi-btn:not(.fi-outlined):is(.fi-color-primary, .fi-color-success) > .fi-icon,
    .fi-tabs-item.fi-active .fi-tabs-item-label,
    .fi-tabs-item.fi-active > .fi-icon {
        color: #fff !important;
    }

    .fi-tabs-item.fi-active .fi-badge {
        background: rgba(255, 255, 255, .22);
        color: #fff;
    }


    /* Scrollbars: thin, a soft thumb on no track (no grey bar and arrows over the glass) */
    html.fi,
    .fi * {
        scrollbar-width: thin;
        scrollbar-color: color-mix(in oklab, var(--glass-ink) 22%, transparent) transparent;
    }

    .fi ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .fi ::-webkit-scrollbar-track {
        background: transparent;
    }

    .fi ::-webkit-scrollbar-thumb {
        background: color-mix(in oklab, var(--glass-ink) 22%, transparent);
        border-radius: 999px;
    }

    /* Overdue / past due: a clear red pane, not a faint pink one */
    html.fi .gx-box-bad {
        background: color-mix(in oklab, var(--danger-500) 16%, var(--glass-pane-strong)) !important;
        box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--danger-500) 40%, transparent);
    }

    html.fi .gx-box-bad > * {
        color: var(--danger-700) !important;
    }

    html.fi .gx-box-bad .gx-box-value {
        font-weight: 800;
    }

    html.fi.dark .gx-box-bad {
        background: color-mix(in oklab, var(--danger-500) 24%, transparent) !important;
    }

    html.fi.dark .gx-box-bad > * {
        color: var(--danger-300) !important;
    }

    /* Uptime squares: a softer mint than Filament's green; red and amber stay strong so a problem stands out */
    html.fi .gx-meter span[style*="--success-500"] {
        background: var(--glass-ok) !important;
    }

    /* Pills: badges as tinted glass chips, the quick filter chips (Orders, Services) as glass pills */
    .fi-badge {
        border-radius: 999px;
        font-weight: 600;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .55), inset 0 0 0 1px color-mix(in oklab, currentColor 20%, transparent) !important;
    }

    .fi-badge.fi-color {
        background: color-mix(in oklab, var(--color-500) 14%, var(--glass-pane-soft)) !important;
    }

    .dark .fi-badge {
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08), inset 0 0 0 1px color-mix(in oklab, currentColor 25%, transparent) !important;
    }

    .dark .fi-badge.fi-color {
        background: color-mix(in oklab, var(--color-500) 22%, transparent) !important;
    }

    html.fi .gx-chip,
    html.fi.dark .gx-chip {
        border-radius: 999px;
        background: var(--glass-pane);
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge), 0 4px 12px -8px rgba(15, 40, 45, .3);
        -webkit-backdrop-filter: blur(12px) saturate(170%);
        backdrop-filter: blur(12px) saturate(170%);
    }

    html.fi .gx-chip:hover {
        background: var(--glass-pane-strong);
    }

    html.fi .gx-chip.gx-chip-on,
    html.fi.dark .gx-chip.gx-chip-on {
        background: color-mix(in oklab, var(--primary-600) 85%, transparent);
        background-image: linear-gradient(180deg, rgba(255, 255, 255, .22), rgba(255, 255, 255, 0) 60%);
        color: #fff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .38), 0 8px 20px -10px var(--primary-600);
    }

    /* Contained tabs (a client's Details, Hosting, ...): the frame's corners follow the pills inside it, so it stays
       rounded the same way when the tabs wrap onto two lines */
    .fi-tabs:not(.fi-contained) {
        background: var(--glass-pane-soft);
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge);
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
        border-radius: 24px;
    }

    .fi-tabs-item {
        border-radius: 999px;
        transition: background-color .2s ease, color .2s ease;
    }

    /* A list's underlined tabs keep the line, under a glass-free row */
    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) {
        -webkit-backdrop-filter: none;
        backdrop-filter: none;
        box-shadow: inset 0 -1px 0 var(--theme-line);
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active {
        box-shadow: none !important;
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active .fi-tabs-item-label {
        color: var(--theme-ink) !important;
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active > .fi-icon {
        color: var(--theme-accent) !important;
    }

    .fi-resource-list-records-page .fi-tabs:not(.fi-contained) .fi-tabs-item.fi-active .fi-badge {
        background: color-mix(in oklab, var(--theme-accent) 14%, transparent);
        color: var(--theme-accent);
    }

    /* The bell and the cart: round glass */
    .fi-topbar .fi-topbar-database-notifications-btn,
    .fi-topbar .gcp-cart-icon {
        background: var(--glass-pane);
        box-shadow: inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge);
        border-radius: 999px;
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
    }

    /* Badges: soft glass chips */
    .fi-badge {
        box-shadow: inset 0 0 0 1px color-mix(in oklab, currentColor 18%, transparent);
    }

    /* Type: Manrope, tight bold headings */
    .fi-header-heading,
    .fi-simple-header-heading,
    .fi-modal-heading {
        font-weight: 700;
        letter-spacing: -.025em;
    }

    .fi-section-header-heading,
    .fi-ta-header-heading,
    .gx-title {
        font-weight: 700;
        letter-spacing: -.01em;
    }

    .fi-wi-stats-overview-stat-value,
    .gx-big,
    .gx-mid {
        font-weight: 800;
        letter-spacing: -.03em;
    }

    /* Motion: a card brightens and its shadow grows on hover (no transform: it would trap modals); pages fade up */
    .fi-wi-stats-overview-stat,
    .gx-card {
        transition: box-shadow .25s ease, background-color .25s ease;
    }

    .fi-wi-stats-overview-stat:hover {
        background: var(--glass-pane-strong) !important;
        box-shadow: var(--theme-shadow-hover), inset 0 1px 0 var(--glass-shine), 0 0 0 1px var(--glass-edge) !important;
    }

    @keyframes glass-in {
        from {
            opacity: 0;
            filter: blur(6px);
        }
    }

    /* opacity and filter only, and backwards: a transform or filter kept afterwards would trap the page's modals */
    .fi-page > section,
    .fi-simple-main {
        animation: glass-in .5s var(--glass-ease) backwards;
    }

    @media (prefers-reduced-motion: reduce) {
        .fi *,
        .fi *::before,
        .fi *::after {
            animation: none !important;
            transition: none !important;
        }
    }

    /* No blur in the browser, or the system asks for less transparency: solid panes, same shapes */
    @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
        html.fi {
            --glass-pane: #ffffff;
            --glass-pane-soft: rgba(255, 255, 255, .85);
            --glass-pane-strong: #ffffff;
        }

        html.fi.dark {
            --glass-pane: #151c1f;
            --glass-pane-soft: #11171a;
            --glass-pane-strong: #1a2226;
        }
    }

    @media (prefers-reduced-transparency: reduce) {
        html.fi {
            --glass-pane: #ffffff;
            --glass-pane-soft: #f4f8f8;
            --glass-pane-strong: #ffffff;
        }

        html.fi.dark {
            --glass-pane: #151c1f;
            --glass-pane-soft: #11171a;
            --glass-pane-strong: #1a2226;
        }

        .fi-body::before {
            background: var(--glass-bg);
        }

        .fi-topbar::before {
            background: var(--glass-pane-strong);
        }
    }
</style>
