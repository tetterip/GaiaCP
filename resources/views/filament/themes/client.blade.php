{{--
    The client area's own views (Home, payment notice, credit, help, banners, sign-in buttons) in the theme's look.
    Loaded on every client panel page (ClientPanelProvider), with or without a theme: each colour is the theme's
    --theme-* variable with Filament's own as the fallback, the way filament.dashboard.styles does it, so Gaia, Flat,
    no theme, light and dark all work. Home's cards are the admin dashboard's .gx-* (filament.dashboard.styles).
--}}
<style>
    html.fi {
        --gcp-card: var(--theme-card, var(--color-white));
        --gcp-line: var(--theme-line, var(--gray-200));
        --gcp-soft: var(--theme-soft, var(--gray-100));
        --gcp-ink: var(--theme-ink, var(--gray-950));
        --gcp-text: var(--theme-ink, var(--gray-700));
        --gcp-muted: var(--theme-muted, var(--gray-500));
        --gcp-accent: var(--theme-accent, var(--primary-600));
        --gcp-radius: var(--theme-radius, .75rem);
        --gcp-radius-control: var(--theme-radius-control, .5rem);
        --gcp-shadow: var(--theme-shadow, 0 1px 2px rgb(0 0 0 / .05));
        --gcp-bad: var(--danger-600);
    }

    html.fi.dark {
        --gcp-card: var(--theme-card, var(--gray-900));
        --gcp-line: var(--theme-line, rgb(255 255 255 / .1));
        --gcp-soft: var(--theme-soft, rgb(255 255 255 / .05));
        --gcp-ink: var(--theme-ink, var(--color-white));
        --gcp-text: var(--theme-ink, var(--gray-300));
        --gcp-muted: var(--theme-muted, var(--gray-400));
        --gcp-accent: var(--theme-accent, var(--primary-400));
        --gcp-bad: var(--danger-400);
    }

    /* A card with the theme's hairline, radius and shadow */
    .gcp-card {
        border-radius: var(--gcp-radius);
        background: var(--gcp-card);
        box-shadow: var(--gcp-shadow), 0 0 0 1px var(--gcp-line);
        padding: 1.4rem 1.5rem;
        color: var(--gcp-text);
    }

    /* Home: the To pay card beside the smaller ones, then shortcuts and lists */
    .gcp-home-grid {
        display: grid;
        gap: 1.25rem;
    }

    @media (min-width: 1024px) {
        .gcp-home-grid {
            grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
            align-items: stretch;
        }
    }

    .gcp-home-side {
        display: grid;
        gap: 1.25rem;
        align-content: start;
    }

    .gcp-home-grid .gx-hero {
        min-height: 0;
        height: 100%;
    }

    .gcp-home-stat .gx-big {
        font-size: 2rem;
    }

    .gcp-home-owed {
        color: var(--gcp-bad);
    }

    .gcp-home-shortcuts {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .gcp-home-shortcuts .fi-btn {
        border-radius: 999px;
        background: var(--gcp-card);
    }

    .gcp-home-list {
        width: 100%;
        border-collapse: collapse;
        font-size: .875rem;
    }

    .gcp-home-list td {
        padding: .65rem 0;
        border-bottom: 1px solid var(--gcp-line);
        color: var(--gcp-text);
    }

    .gcp-home-list tr:last-child td {
        border-bottom: 0;
    }

    .gcp-home-list a {
        color: var(--gcp-accent);
        font-weight: 500;
    }

    .gcp-home-list a:hover {
        text-decoration: underline;
    }

    .gcp-end {
        text-align: end;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .gcp-home-list .gcp-overdue {
        color: var(--gcp-bad);
    }

    .gcp-home-note {
        font-size: .875rem;
        color: var(--gcp-muted);
    }

    /* Credit: the balance over the history */
    .gcp-credit-balance {
        max-width: 24rem;
    }

    .gcp-credit-balance .gx-big {
        font-size: 2rem;
    }

    /* Help: the guide */
    .gcp-help {
        max-width: 48rem;
        font-size: .9375rem;
        line-height: 1.65;
        color: var(--gcp-text);
    }

    .gcp-help h2 {
        margin: 1.75rem 0 .5rem;
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-help h2:first-child {
        margin-top: 0;
    }

    .gcp-help p {
        margin: .5rem 0;
    }

    .gcp-help ul {
        margin: .5rem 0;
        padding-inline-start: 1.25rem;
        list-style: disc;
    }

    .gcp-help li {
        margin: .25rem 0;
    }

    .gcp-help strong {
        color: var(--gcp-ink);
        font-weight: 600;
    }

    /* Server uptime on a hosting account's page: the dashboard's squares light up one after the other */
    .gcp-server-uptime {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 22rem), 1fr));
        gap: 1rem 1.5rem;
        align-items: start;
    }

    .gcp-server-uptime .gx-meter [role="img"] > span[data-on] {
        animation: gcp-uptime-light .18s ease-out backwards;
        animation-delay: calc(var(--gx-i, 0) * 45ms + 150ms);
    }

    @keyframes gcp-uptime-light {
        from { background: rgb(127 127 127 / .2); }
    }

    @media (prefers-reduced-motion: reduce) {
        .gcp-server-uptime .gx-meter [role="img"] > span[data-on] {
            animation: none;
        }
    }

    /* The server's state before a hosting account's domain (ServiceResource::domainWithState()), pulsing while up or down */
    .gcp-live-wrap {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
    }

    .gcp-live {
        position: relative;
        flex: none;
        width: .55rem;
        height: .55rem;
        border-radius: 999px;
        background: var(--gray-400);
        cursor: help;
    }

    .gcp-live-up { background: var(--success-500); }
    .gcp-live-down { background: var(--danger-500); }
    .gcp-live-maintenance { background: var(--warning-500); }

    .gcp-live-up::after,
    .gcp-live-down::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: inherit;
        animation: gcp-live-pulse 2s ease-out infinite;
    }

    @keyframes gcp-live-pulse {
        from { transform: scale(1); opacity: .6; }
        to { transform: scale(2.6); opacity: 0; }
    }

    @media (prefers-reduced-motion: reduce) {
        .gcp-live::after { animation: none; }
    }

    /* Top bar: the cart and the bell sit at its right end, after the search */
    .fi-topbar-end > .fi-global-search-ctn {
        margin-inline-end: auto;
    }

    /* The cart's items (Cart, PublicOrder): one row each, its price and Remove on the right */
    .gcp-cart-lines {
        display: flex;
        flex-direction: column;
    }

    .gcp-cart-line {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: .75rem 0;
        border-bottom: 1px solid var(--gray-200);
    }

    .dark .gcp-cart-line {
        border-color: var(--gray-700);
    }

    .gcp-cart-line:first-child {
        padding-top: 0;
    }

    .gcp-cart-line-title {
        font-weight: 600;
    }

    .gcp-cart-line-detail,
    .gcp-cart-empty {
        font-size: .875rem;
        color: var(--gray-500);
    }

    .gcp-cart-line-error {
        font-size: .875rem;
        color: var(--danger-600);
    }

    .gcp-cart-line-end {
        display: flex;
        align-items: center;
        gap: .5rem;
        white-space: nowrap;
    }

    .gcp-cart-line-amount {
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    /* Domain search (SearchDomains): the box and its button on one row, results as cart lines that wrap on phones */
    .gcp-domain-search {
        display: flex;
        gap: .5rem;
    }

    .gcp-domain-search-input {
        flex: 1;
    }

    .gcp-domain-search ~ .fi-section .gcp-cart-line {
        flex-wrap: wrap;
        align-items: center;
    }

    .gcp-domain-search ~ .fi-section .gcp-cart-line-end {
        flex-wrap: wrap;
    }

    .gcp-domain-status {
        margin-top: .25rem;
        width: fit-content;
    }

    .gcp-domain-note,
    .gcp-domain-suggest {
        margin-top: .75rem;
    }

    /* Knowledge base (KnowledgeBase, KnowledgeBaseArticle, the tips on Open a ticket) */
    .gcp-kb-groups {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 768px) {
        .gcp-kb-groups {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
        }
    }

    .gcp-kb-link {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: .15rem 1rem;
        padding: .55rem 0;
        color: var(--gcp-ink);
        font-weight: 500;
    }

    .gcp-kb-link + .gcp-kb-link {
        border-top: 1px solid var(--gcp-line);
    }

    .gcp-kb-link:hover {
        color: var(--gcp-accent);
    }

    .gcp-kb-meta {
        font-size: .875rem;
        font-weight: 400;
        color: var(--gcp-muted);
    }

    .gcp-kb-tips {
        padding: .75rem 1rem;
        border-radius: var(--gcp-radius-control);
        background: var(--gcp-soft);
    }

    .gcp-kb-inline {
        color: var(--gcp-accent);
        font-weight: 500;
    }

    .gcp-kb-vote {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem .75rem;
    }

    .gcp-kb-vote .gcp-kb-meta {
        margin-inline-start: auto;
    }

    .gcp-kb-article h1, .gcp-kb-article h3 {
        margin: 1.25rem 0 .5rem;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-kb-article ol {
        margin: .5rem 0;
        padding-inline-start: 1.25rem;
        list-style: decimal;
    }

    .gcp-kb-article a {
        color: var(--gcp-accent);
        text-decoration: underline;
    }

    .gcp-kb-article pre, .gcp-kb-article code {
        font-family: ui-monospace, monospace;
        font-size: .875rem;
    }

    .gcp-kb-article pre {
        margin: .75rem 0;
        padding: .75rem 1rem;
        overflow-x: auto;
        border-radius: var(--gcp-radius-control);
        background: var(--gcp-soft);
    }

    /* Banners: a soft tint like the two-factor reminder */
    .gcp-maintenance {
        padding: .5rem 1rem;
        text-align: center;
        font-size: .875rem;
        color: var(--gcp-ink);
        background: color-mix(in oklab, var(--warning-500) 14%, var(--gcp-card));
        border-bottom: 1px solid color-mix(in oklab, var(--warning-500) 30%, transparent);
    }
    .gcp-viewing-as a {
        margin-inline-start: .5rem;
        font-weight: 600;
        text-decoration: underline;
    }

    .gcp-incident {
        display: flex;
        gap: .75rem;
        margin: 1.5rem 0 0;
        padding: .875rem 1rem;
        border-radius: var(--gcp-radius-control);
        border: 1px solid color-mix(in oklab, var(--warning-500) 35%, transparent);
        background: color-mix(in oklab, var(--warning-500) 10%, var(--gcp-card));
        font-size: .875rem;
        color: var(--gcp-text);
    }

    .gcp-incident.is-down {
        border-color: color-mix(in oklab, var(--danger-500) 35%, transparent);
        background: color-mix(in oklab, var(--danger-500) 10%, var(--gcp-card));
    }

    .gcp-incident strong {
        display: block;
        color: var(--gcp-ink);
        font-weight: 600;
    }

    .gcp-incident p {
        margin: .25rem 0 0;
        white-space: pre-line;
    }

    .gcp-incident ul {
        margin: .375rem 0 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-wrap: wrap;
        gap: .25rem .75rem;
    }

    .gcp-incident li {
        font-weight: 500;
        color: var(--gcp-ink);
        overflow-wrap: anywhere;
    }

    /* Messages to clients shown on Home (ClientMessage) */
    .gcp-incident.is-news {
        margin: 0 0 1rem;
        border-color: color-mix(in oklab, var(--primary-500) 35%, transparent);
        background: color-mix(in oklab, var(--primary-500) 8%, var(--gcp-card));
    }

    .gcp-incident.is-news .gcp-incident-icon {
        color: var(--primary-600);
    }

    .gcp-incident .gcp-news-body > * {
        margin: .25rem 0 0;
    }

    .gcp-incident .gcp-news-body ul {
        display: block;
        padding-left: 1.25rem;
        list-style: disc;
    }

    .gcp-incident-icon {
        flex: none;
        width: 1.5rem;
        height: 1.5rem;
        color: var(--warning-600);
    }

    .gcp-incident.is-down .gcp-incident-icon {
        color: var(--danger-600);
    }

    /* The status page (ServerStatus): every server works, or the ones that don't */
    .gcp-incident.is-ok, .gcp-incident.is-status {
        margin: 0;
    }

    .gcp-incident.is-ok {
        align-items: center;
        border-color: color-mix(in oklab, var(--success-500) 35%, transparent);
        background: color-mix(in oklab, var(--success-500) 10%, var(--gcp-card));
    }

    .gcp-incident.is-ok .gcp-incident-icon {
        color: var(--success-600);
    }

    .gcp-status-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem .75rem;
        margin-bottom: 1rem;
    }

    .gcp-status-head strong {
        color: var(--gcp-ink);
        font-weight: 600;
        overflow-wrap: anywhere;
    }

    /* Announcements */
    .gcp-news-list {
        display: grid;
        gap: 1rem;
    }

    .gcp-news-title {
        display: block;
        color: var(--gcp-ink);
        font-weight: 600;
        font-size: 1.0625rem;
    }

    .gcp-news-title:hover {
        color: var(--primary-600);
    }

    .gcp-news-excerpt {
        margin: .5rem 0 0;
        color: var(--gcp-text);
        font-size: .875rem;
    }

    .gcp-news-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
    }

    .gcp-news-feed {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        font-size: .875rem;
    }

    .gcp-news-feed-icon {
        width: 1rem;
        height: 1rem;
    }

    /* Under the login and sign up forms: the sign-in plugins' buttons */
    .gcp-signin {
        display: grid;
        gap: 1rem;
    }

    .gcp-signin-or {
        display: flex;
        align-items: center;
        gap: .75rem;
        font-size: .875rem;
        color: var(--gcp-muted);
    }

    .gcp-signin-or::before,
    .gcp-signin-or::after {
        content: "";
        flex: 1;
        height: 1px;
        background: var(--gcp-line);
    }

    .gcp-signin-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .75rem;
        width: 100%;
        padding: .5rem .75rem;
        border-radius: var(--gcp-radius-control);
        border: 1px solid var(--gcp-line);
        background: var(--gcp-card);
        color: var(--gcp-ink);
        font-size: .875rem;
        font-weight: 600;
        transition: background-color .15s;
    }

    .gcp-signin-button:hover {
        background: var(--gcp-soft);
    }

    .gcp-signin-button svg {
        width: 1.25rem;
        height: 1.25rem;
        flex-shrink: 0;
    }

    .gcp-cart-link {
        text-align: center;
    }

    /* /cart and Order: the products as cards, the summary beside them */
    .gcp-product-cards.fi-fo-radio {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
        gap: .75rem;
        margin-top: 0;
    }

    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label {
        align-self: stretch;
        padding: 1rem 1.1rem;
        border-radius: calc(var(--gcp-radius-control) + 4px);
        background: var(--gcp-card);
        box-shadow: 0 0 0 1px var(--gcp-line);
        cursor: pointer;
        transition: box-shadow .15s ease, background-color .15s ease;
    }

    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:hover {
        background: var(--gcp-soft);
    }

    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has(.fi-radio-input:checked) {
        box-shadow: 0 0 0 2px var(--gcp-accent);
    }

    .gcp-product-cards .fi-fo-radio-label-text > p:first-child {
        font-size: .95rem;
        font-weight: 600;
    }

    /* A card's name with its icon (OrderForm::cardLabel(), the public home page) */
    .gcp-card-name {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .25rem .5rem;
    }

    .gcp-product-icon {
        color: var(--gcp-accent);
    }

    .gcp-group-cards .fi-fo-radio-label-text > p:first-child {
        font-size: 1rem;
    }

    /* The group's compare table (filament.client.compare-products) */
    .gcp-compare summary {
        cursor: pointer;
        font-weight: 600;
        font-size: .9rem;
        color: var(--gcp-ink);
    }

    .gcp-compare-wrap {
        overflow-x: auto;
        margin-top: .75rem;
    }

    .gcp-compare-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .875rem;
    }

    .gcp-compare-table th,
    .gcp-compare-table td {
        padding: .55rem .75rem;
        border-bottom: 1px solid var(--gcp-line);
        text-align: center;
        white-space: nowrap;
    }

    .gcp-compare-table tbody th {
        text-align: start;
        font-weight: 500;
        color: var(--gcp-muted);
    }

    .gcp-compare-table thead th {
        vertical-align: bottom;
    }

    /* The feature names stay in view while a narrow screen scrolls the packages sideways */
    .gcp-compare-table tr > :first-child {
        position: sticky;
        inset-inline-start: 0;
        z-index: 1;
        background: var(--gcp-card);
    }

    .gcp-compare-name {
        display: block;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-compare-price {
        display: block;
        font-weight: 400;
        font-size: .8rem;
        color: var(--gcp-muted);
    }

    .gcp-compare-table .gcp-compare-chosen {
        background: var(--gcp-soft);
    }

    .gcp-compare-yes {
        width: 1.1rem;
        height: 1.1rem;
        margin: 0 auto;
        color: var(--gcp-accent);
    }

    .gcp-compare-no {
        color: var(--gcp-muted);
    }

    .gcp-compare-amount {
        display: block;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-compare-table .gcp-compare-first-cycle > * {
        border-top: 2px solid var(--gcp-line);
    }

    .gcp-compare-table tfoot td,
    .gcp-compare-table tfoot th {
        border-bottom: 0;
        padding-top: .75rem;
    }

    /* A product marked Popular: the badge on its card, the public home page and the compare table */
    .gcp-popular {
        display: inline-block;
        padding: .1rem .5rem;
        border-radius: 999px;
        background: color-mix(in srgb, var(--gcp-accent) 15%, transparent);
        color: var(--gcp-accent);
        font-size: .7rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }

    .gcp-compare-table thead .gcp-popular {
        margin-bottom: .3rem;
    }

    /* A product's tags (Settings → Product tags): the Popular badge in the tag's own colour */
    .gcp-tag {
        background: color-mix(in srgb, var(--gcp-tag) 14%, transparent);
        color: var(--gcp-tag);
    }

    .dark .gcp-tag {
        background: color-mix(in srgb, var(--gcp-tag) 25%, transparent);
        color: color-mix(in srgb, var(--gcp-tag) 55%, white);
    }

    /* A product on sale (Product::onSale()): the Sale badge, and the normal price struck through beside the sale one */
    .gcp-sale {
        --gcp-tag: #dc2626;
        background: color-mix(in srgb, var(--gcp-tag) 14%, transparent);
        color: var(--gcp-tag);
    }

    .dark .gcp-sale {
        background: color-mix(in srgb, var(--gcp-tag) 25%, transparent);
        color: color-mix(in srgb, var(--gcp-tag) 55%, white);
    }

    /*
     * A product's own colour (Product::orderColor(), data-gcp-color on its card, compare column and public home card):
     * the accent there follows it (name, icon, price, check marks, Popular badge, buttons), lighter in dark mode, and
     * the card gets a top border in it. Tags and Sale keep their own colours.
     */
@foreach (\App\Models\ProductTag::COLORS as $key => $hex)
    [data-gcp-color="{{ $key }}"],
    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has([data-gcp-color="{{ $key }}"]) {
        --gcp-product: {{ $hex }};
    }
@endforeach

    [data-gcp-color],
    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has([data-gcp-color]) {
        --gcp-accent: var(--gcp-product);
    }

    .dark [data-gcp-color],
    .dark .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has([data-gcp-color]) {
        --gcp-accent: color-mix(in srgb, var(--gcp-product) 60%, white);
    }

    .gcp-card-name[data-gcp-color] > span:not(.gcp-popular),
    [data-gcp-color] .gcp-public-product-name,
    [data-gcp-color] .gcp-compare-name {
        color: var(--gcp-accent);
    }

    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has([data-gcp-color]) {
        box-shadow: inset 0 3px 0 var(--gcp-accent), 0 0 0 1px var(--gcp-line);
    }

    .gcp-product-cards.fi-fo-radio > .fi-fo-radio-label:has([data-gcp-color]):has(.fi-radio-input:checked) {
        box-shadow: inset 0 3px 0 var(--gcp-accent), 0 0 0 2px var(--gcp-accent);
    }

    .gcp-public-product[data-gcp-color] {
        box-shadow: var(--gcp-shadow), inset 0 3px 0 var(--gcp-accent), 0 0 0 1px var(--gcp-line);
    }

    [data-gcp-color] .fi-btn.fi-color:not([disabled]) {
        --bg: var(--gcp-product);
        --hover-bg: color-mix(in srgb, var(--gcp-product) 85%, black);
        --dark-bg: var(--gcp-product);
        --dark-hover-bg: color-mix(in srgb, var(--gcp-product) 85%, white);
        --text: #fff;
        --hover-text: #fff;
        --dark-text: #fff;
        --dark-hover-text: #fff;
        /* themes that paint buttons themselves (Gaia) */
        --gaia-button: var(--gcp-product);
        --gaia-button-ink: #fff;
    }

    .gcp-was {
        opacity: .65;
        font-weight: 400;
    }

    .gcp-compare-badges {
        display: flex;
        flex-wrap: wrap;
        gap: .25rem;
    }

    .gcp-compare-badges .gcp-popular {
        margin-bottom: .3rem;
    }

    .gcp-public-product-name {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .25rem .5rem;
    }

    .gcp-public-group-title {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .gcp-public-group-title a {
        color: inherit;
    }

    .gcp-public-group-title a:hover {
        text-decoration: underline;
    }

    @media (min-width: 1024px) {
        .gcp-order-summary {
            position: sticky;
            top: 1.5rem;
        }
    }

    /* A hosting account's Quick shortcuts: icon tiles, 3 a row (2 on phones) */
    .gcp-shortcuts {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
    }

    @media (min-width: 640px) {
        .gcp-shortcuts {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .gcp-shortcut {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .6rem;
        padding: 1.1rem .75rem;
        border-radius: calc(var(--gcp-radius-control) + 4px);
        background: var(--gcp-soft);
        color: var(--gcp-ink);
        font-size: .875rem;
        font-weight: 500;
        text-align: center;
        overflow-wrap: anywhere;
        transition: background-color .15s ease, box-shadow .15s ease;
    }

    .gcp-shortcut:hover {
        background: var(--gcp-card);
        box-shadow: 0 0 0 1px var(--gcp-line), var(--gcp-shadow);
    }

    .gcp-shortcut:focus-visible {
        outline: 2px solid var(--gcp-accent);
        outline-offset: 2px;
    }

    .gcp-shortcut-icon {
        width: 2rem;
        height: 2rem;
        color: var(--gcp-accent);
    }

    /* A payment notice as the client sees and prints it */
    .gcp-notice {
        padding: 2rem;
        font-size: .875rem;
        line-height: 1.5;
    }

    .gcp-notice h2 {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-notice h3 {
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--gcp-muted);
        margin-bottom: .25rem;
    }

    .gcp-notice-logo {
        display: block;
        max-height: 4rem;
        max-width: 16rem;
        margin-bottom: .75rem;
    }

    .gcp-notice-logo-dark {
        display: none;
    }

    .dark .gcp-notice-logo:has(+ .gcp-notice-logo-dark) {
        display: none;
    }

    .dark .gcp-notice-logo-dark {
        display: block;
    }

    .gcp-notice-head {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .gcp-notice-meta {
        display: grid;
        grid-template-columns: auto auto;
        gap: .2rem 1rem;
        align-content: start;
        padding: .9rem 1.1rem;
        border-radius: 1rem;
        background: var(--gcp-soft);
    }

    .gcp-notice-meta dt {
        color: var(--gcp-muted);
    }

    .gcp-notice-meta dd {
        text-align: end;
        font-variant-numeric: tabular-nums;
    }

    .gcp-notice-overdue {
        color: var(--gcp-bad);
        font-weight: 600;
    }

    .gcp-notice-lines {
        width: 100%;
        border-collapse: collapse;
        margin: 1.5rem 0 1rem;
    }

    .gcp-notice-lines th {
        padding: .55rem .5rem;
        text-align: start;
        font-weight: 600;
        color: var(--gcp-ink);
        border-bottom: 1px solid var(--gcp-line);
    }

    .gcp-notice-lines td {
        padding: .55rem .5rem;
        border-bottom: 1px solid var(--gcp-line);
        vertical-align: top;
    }

    .gcp-notice-period {
        display: block;
        color: var(--gcp-muted);
        font-size: .8125rem;
    }

    .gcp-notice-totals {
        margin-inline-start: auto;
        width: min(100%, 20rem);
        display: grid;
        grid-template-columns: 1fr auto;
        row-gap: .3rem;
    }

    .gcp-notice-totals .gcp-end {
        padding-inline-start: 1rem;
    }

    .gcp-notice-totals .gcp-total {
        font-weight: 600;
        font-size: 1rem;
        color: var(--gcp-ink);
        border-top: 1px solid var(--gcp-line);
        padding-top: .4rem;
    }

    .gcp-notice-pay {
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid var(--gcp-line);
    }

    .gcp-notice-pay p + p {
        margin-top: .5rem;
    }

    .gcp-notice-tax-note {
        margin-top: 1rem;
        text-align: end;
        font-size: .875rem;
    }

    .gcp-notice-note {
        margin-top: 1.5rem;
        font-size: .8125rem;
        color: var(--gcp-muted);
    }

    @page {
        margin: 18mm;
    }

    @media print {
        /* Dark text on white paper, whatever the screen's scheme */
        html.fi,
        html.fi.dark {
            --gcp-ink: #000;
            --gcp-text: #000;
            --gcp-muted: #555;
            --gcp-line: #ddd;
            --gcp-soft: transparent;
        }

        .gcp-notice .gcp-notice-logo {
            display: block !important;
        }

        .gcp-notice .gcp-notice-logo-dark {
            display: none !important;
        }

        .fi-sidebar,
        .fi-topbar,
        .fi-header,
        .fi-sidebar-close-overlay,
        .fi-footer,
        footer {
            display: none !important;
        }

        .fi-main,
        .fi-page,
        .fi-main-ctn {
            padding: 0 !important;
            margin: 0 !important;
            max-width: none !important;
            box-shadow: none !important;
        }

        body,
        .fi-body {
            background: #fff !important;
        }

        .gcp-notice {
            border: 0;
            box-shadow: none;
            padding: 0;
            color: #000;
        }

        .gcp-notice-meta {
            background: none;
            padding: 0;
        }
    }

    /* The public area (PublicHome, DomainPrices, the visitor's top bar): the welcome card, then cards by type */
    .gcp-public-hero {
        display: grid;
        gap: 1rem;
        padding: 2rem 1.5rem;
    }

    .gcp-public-title {
        font-size: 1.75rem;
        line-height: 1.2;
        font-weight: 700;
        color: var(--gcp-ink);
    }

    .gcp-public-text {
        color: var(--gcp-muted);
        max-width: 48rem;
    }

    .gcp-public-text p + p,
    .gcp-public-text ul,
    .gcp-public-text ol {
        margin-top: .5rem;
    }

    .gcp-public-text ul {
        list-style: disc;
        padding-inline-start: 1.25rem;
    }

    .gcp-public-text a {
        color: var(--gcp-accent);
        text-decoration: underline;
    }

    /* A product group's public page (ProductGroupPage): its questions, each opening its answer */
    .gcp-faq {
        display: grid;
        padding: .25rem 1.25rem;
    }

    .gcp-faq details + details {
        border-top: 1px solid var(--gcp-line);
    }

    .gcp-faq summary {
        cursor: pointer;
        padding: .85rem 0;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-faq details .gcp-public-text {
        padding-bottom: .85rem;
    }

    .gcp-public-title svg {
        display: inline-block;
        width: 1.5rem;
        height: 1.5rem;
        vertical-align: -.15rem;
    }

    /* Contact us: the company's details beside the form (above it on a phone) */
    .gcp-contact {
        display: flex;
        flex-direction: column;
        gap: .85rem;
    }

    .gcp-contact-name {
        font-size: 1.05rem;
        font-weight: 600;
    }

    .gcp-contact-line {
        display: flex;
        gap: .65rem;
        align-items: flex-start;
        overflow-wrap: anywhere;
    }

    .gcp-contact-line > div {
        display: flex;
        flex-direction: column;
    }

    .gcp-contact-line a {
        color: var(--gcp-accent);
    }

    .gcp-contact-icon {
        width: 1.25rem;
        height: 1.25rem;
        flex-shrink: 0;
        margin-top: .1rem;
        color: var(--gcp-muted);
    }

    .gcp-contact-value {
        white-space: pre-line;
    }

    .gcp-contact-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: .25rem;
    }

    .gcp-public-hero .gcp-domain-search {
        max-width: 40rem;
    }

    .gcp-public-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .gcp-public-group {
        display: grid;
        gap: .75rem;
    }

    .gcp-public-group-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-public-cards {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
    }

    .gcp-public-product {
        display: flex;
        flex-direction: column;
        gap: .75rem;
    }

    .gcp-public-product-name {
        font-weight: 600;
        color: var(--gcp-ink);
    }

    .gcp-public-product-price {
        flex: 1;
    }

    .gcp-public-price-main {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--gcp-accent);
        font-variant-numeric: tabular-nums;
    }

    .gcp-public-domains {
        display: grid;
        gap: .75rem;
    }

    .gcp-public-topbar {
        margin-inline-start: auto; /* at the right end: a visitor has no search before it */
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    /* Light / dark mode for visitors: one icon, the three choices in its dropdown */
    .gcp-public-theme-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: var(--gcp-radius-control);
        color: var(--gray-500);
    }

    .gcp-public-theme-btn:hover {
        color: var(--gcp-ink);
        background: rgb(127 127 127 / .1);
    }

    .gcp-public-theme-btn svg {
        width: 1.25rem;
        height: 1.25rem;
    }

    .gcp-public-back {
        margin-bottom: 1rem;
    }

    .gcp-domain-prices {
        display: grid;
        gap: 1rem;
    }

    .gcp-domain-prices .gcp-domain-search {
        flex-wrap: wrap;
    }

    .gcp-price-table-wrap {
        overflow-x: auto;
    }

    .gcp-price-table td,
    .gcp-price-table th {
        white-space: nowrap;
    }

    .gcp-price-table th {
        padding: 0 0 .5rem;
        text-align: start;
        font-size: .75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--gcp-muted);
        border-bottom: 1px solid var(--gcp-line);
    }

    .gcp-price-table th.gcp-end {
        text-align: end;
    }

    .gcp-price-table td + td,
    .gcp-price-table th + th {
        padding-inline-start: 1rem;
    }

    .gcp-price-tld {
        font-weight: 600;
        color: var(--gcp-ink);
    }

    /* Contact form: a field only bots fill in (ContactSupport) */
    .gcp-hp,
    .fi-grid-col:has(> .fi-sc-component > .fi-fo-field .gcp-hp) {
        position: absolute !important;
        left: -10000px !important;
        width: 1px;
        height: 1px;
        overflow: hidden;
    }

    .gcp-visitor-ticket {
        display: grid;
        gap: 1.5rem;
        align-items: start;
    }

    @media (min-width: 64rem) {
        .gcp-visitor-ticket {
            grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
        }
    }

    .gcp-visitor-ticket-details {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: .4rem 1rem;
        margin-bottom: 1rem;
        font-size: .875rem;
    }

    .gcp-visitor-ticket-details dt {
        color: var(--gcp-muted);
    }

    .gcp-visitor-ticket-details dd {
        color: var(--gcp-ink);
        overflow-wrap: anywhere;
    }

    @if (\App\Themes\Themes::brandColor())
        /* Settings → Client area → Brand colour: the theme's accent follows the primary colour made from it */
        html.fi {
            --theme-accent: var(--primary-600);
            --gaia-accent: var(--primary-700);
        }

        html.fi.dark {
            --theme-accent: var(--primary-400);
            --gaia-accent: var(--primary-300);
        }
    @endif
</style>
