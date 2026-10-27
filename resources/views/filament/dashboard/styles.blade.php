{{-- The dashboard's cards (App\Filament\Widgets): 12 columns on wide screens, in Filament's colours, so they work with
     any theme or none. A theme may restyle .gx-* (Gaia sets the serif figures). Included once per page. --}}
@once
<style>
    /* The dashboard (cards.blade.php): two columns of the same width on a wide screen at any width, each filling down
       on its own (masonry), one after the other on a phone; an empty column still takes a dragged card */
    .gx-dash {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
    }

    @media (min-width: 768px) {
        .gx-dash {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .gx-col {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        min-width: 0;
    }

    @media (min-width: 768px) {
        .gx-col {
            min-height: 8rem;
        }
    }

    .gx-slot {
        position: relative;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    /* The drag handle, at the middle of the card's top edge: shown on hover or focus, always on a touch screen */
    .gx-grip {
        position: absolute;
        top: .2rem;
        left: 50%;
        z-index: 2;
        display: grid;
        place-items: center;
        width: 3rem;
        height: 1.4rem;
        border-radius: 999px;
        transform: translateX(-50%);
        color: var(--theme-muted, var(--gray-400));
        cursor: grab;
        touch-action: none;
        opacity: 0;
        transition: opacity .15s ease, background-color .15s ease;
    }

    .gx-grip svg {
        width: 1.25rem;
        height: .5rem;
        fill: currentColor;
    }

    .gx-slot:hover > .gx-grip,
    .gx-grip:focus-visible {
        opacity: 1;
    }

    .gx-grip:hover,
    .gx-grip:focus-visible {
        background: var(--theme-soft, var(--gray-100));
        color: var(--theme-ink, var(--gray-700));
    }

    .dark .gx-grip:hover,
    .dark .gx-grip:focus-visible {
        background: var(--theme-soft, color-mix(in oklab, var(--color-white) 8%, transparent));
        color: var(--gray-200);
    }

    .gx-grip:active {
        cursor: grabbing;
    }

    @media (hover: none) {
        .gx-grip {
            opacity: .7;
            width: 3.5rem;
            height: 1.6rem;
        }
    }

    /* The card being dragged leaves a faint copy where it would land */
    .gx-dash .fi-sortable-ghost {
        opacity: .35;
    }

    @media (prefers-reduced-motion: reduce) {
        .gx-grip {
            transition: none;
        }
    }

    .gx-card {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.4rem 1.5rem;
        box-sizing: border-box;
        min-width: 0;
        overflow: hidden;
    }

    .gx-muted {
        color: var(--theme-muted, var(--gray-500));
        font-size: .85rem;
    }

    .gx-strong {
        font-weight: 600;
        font-size: .9rem;
    }

    .gx-grow {
        flex: 1;
    }

    .gx-num {
        font-size: .82rem;
        font-variant-numeric: tabular-nums;
    }

    .gx-big,
    .gx-mid,
    .gx-title {
        font-weight: 600;
        letter-spacing: -.01em;
    }

    .gx-big {
        font-size: 3rem;
        line-height: 1;
    }

    .gx-mid {
        font-size: 2rem;
        line-height: 1.1;
    }

    .gx-title {
        margin: 0;
        font-size: 1.25rem;
        flex: 1;
    }

    .gx-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .75rem;
    }

    .gx-ico {
        display: grid;
        place-items: center;
        flex: none;
        width: 2.15rem;
        height: 2.15rem;
        border-radius: .65rem;
        background: var(--theme-soft, var(--gray-100));
        color: var(--theme-ink, var(--gray-700));
    }

    .gx-ico-svg {
        width: 1.15rem;
        height: 1.15rem;
    }

    .gx-tone-warn { background: var(--warning-50); color: var(--warning-700); }
    .gx-tone-bad { background: var(--danger-50); color: var(--danger-700); }
    .gx-tone-info { background: var(--primary-50); color: var(--primary-700); }
    .gx-tone-ok { background: var(--success-50); color: var(--success-700); }
    .dark .gx-tone-warn { background: color-mix(in oklab, var(--warning-500) 16%, transparent); color: var(--warning-300); }
    .dark .gx-tone-bad { background: color-mix(in oklab, var(--danger-500) 16%, transparent); color: var(--danger-300); }
    .dark .gx-tone-info { background: color-mix(in oklab, var(--primary-500) 16%, transparent); color: var(--primary-300); }
    .dark .gx-tone-ok { background: color-mix(in oklab, var(--success-500) 16%, transparent); color: var(--success-300); }

    .gx-pill {
        display: inline-flex;
        align-items: center;
        padding: .1rem .55rem;
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 600;
    }

    .gx-pill-ok { background: var(--success-50); color: var(--success-700); }
    .gx-pill-bad { background: var(--danger-50); color: var(--danger-700); }
    .gx-pill-warn { background: var(--warning-50); color: var(--warning-700); }
    .dark .gx-pill-ok { background: color-mix(in oklab, var(--success-500) 16%, transparent); color: var(--success-300); }
    .dark .gx-pill-bad { background: color-mix(in oklab, var(--danger-500) 16%, transparent); color: var(--danger-300); }
    .dark .gx-pill-warn { background: color-mix(in oklab, var(--warning-500) 16%, transparent); color: var(--warning-300); }

    .gx-link {
        font-size: .85rem;
        font-weight: 600;
        color: var(--theme-accent, var(--primary-600));
    }

    /* Balance card */
    .gx-hero {
        min-height: 20rem;
        padding: 1.6rem;
        gap: 1.1rem;
    }

    .gx-hero-label {
        display: flex;
        align-items: center;
        gap: .6rem;
        font-size: .85rem;
        font-weight: 500;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-dot {
        width: .5rem;
        height: .5rem;
        border-radius: 50%;
        background: var(--success-600);
    }

    .gx-hero-amount {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        gap: .4rem;
    }

    .gx-boxes,
    .gx-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .6rem;
    }

    /* The balance card's boxes one under the other, its two buttons half the width each */
    .gx-boxes-stacked {
        flex-direction: column;
    }

    .gx-boxes-stacked > .gx-box {
        flex: none;
    }

    .gx-actions-split > * {
        flex: 1 1 9rem;
    }

    .gx-box {
        flex: 1 1 8.5rem;
        padding: .75rem .9rem;
        border-radius: 1rem;
        background: var(--theme-soft, var(--gray-100));
    }

    .dark .gx-box {
        background: var(--theme-soft, color-mix(in oklab, var(--color-white) 5%, transparent));
    }

    .gx-box-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-box-value {
        font-size: 1.15rem;
        font-weight: 600;
        margin-top: .1rem;
    }

    .gx-box-sub {
        font-size: .78rem;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-box-bad,
    .dark .gx-box-bad {
        background: var(--danger-50);
    }

    .dark .gx-box-bad {
        background: color-mix(in oklab, var(--danger-500) 16%, transparent);
    }

    .gx-box-bad > * {
        color: var(--danger-700) !important;
    }

    .dark .gx-box-bad > * {
        color: var(--danger-300) !important;
    }

    /* Chart card */
    .gx-seg {
        display: inline-flex;
        padding: .2rem;
        border-radius: calc(var(--theme-radius-control, .5rem) + 2px);
        box-shadow: inset 0 0 0 1px var(--theme-line, var(--gray-200));
    }

    .gx-seg button {
        min-height: 2rem;
        padding: 0 .8rem;
        border-radius: calc(var(--theme-radius-control, .5rem) - 2px);
        font-size: .82rem;
        font-weight: 500;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-seg button.gx-on {
        background: var(--gaia-button, var(--primary-600));
        color: var(--gaia-button-ink, #fff);
    }

    .gx-legend,
    .gx-totals {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .4rem 1rem;
    }

    .gx-legend span {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: .8rem;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-key {
        width: .65rem;
        height: .65rem;
        border-radius: 3px;
    }

    .gx-key-income {
        width: .9rem;
        height: 3px;
    }

    .gx-totals {
        align-items: baseline;
    }

    .gx-totals b {
        color: var(--theme-ink, var(--gray-950));
        font-weight: 600;
    }

    .gx-plot {
        display: flex;
        gap: .6rem;
        flex: 1;
        min-height: 13rem;
        transition: opacity .2s ease;
    }

    .gx-plot.gx-loading {
        opacity: .5;
    }

    .gx-axis {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 0 0 1.6rem;
        font-size: .7rem;
        text-align: right;
        white-space: nowrap;
        color: var(--theme-muted, var(--gray-500));
    }

    .gx-plot-main {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: .5rem;
    }

    .gx-plot-main svg {
        width: 100%;
        height: 12rem;
        display: block;
        overflow: visible;
    }

    .gx-labels {
        position: relative;
        height: 1rem;
        font-size: .7rem;
        color: var(--theme-muted, var(--gray-500));
    }

    /* Each label sits under the middle of its bar */
    .gx-labels span {
        position: absolute;
        top: 0;
        transform: translateX(-50%);
        white-space: nowrap;
    }

    .gx-grid { stroke: var(--theme-line, var(--gray-200)); }
    .gx-key-orders, .gx-bar-orders { background: var(--success-200); fill: var(--success-200); }
    .gx-key-completed, .gx-bar-completed { background: var(--success-600); fill: var(--success-600); }
    .gx-key-income { background: var(--primary-500); }
    .gx-area { fill: var(--primary-500); fill-opacity: .14; }
    .gx-line { stroke: var(--primary-500); stroke-width: 2.4; stroke-linejoin: round; stroke-linecap: round; }
    .dark .gx-key-orders, .dark .gx-bar-orders { background: var(--success-900); fill: var(--success-900); }
    .dark .gx-key-completed, .dark .gx-bar-completed { background: var(--success-400); fill: var(--success-400); }
    .dark .gx-key-income { background: var(--primary-400); }
    .dark .gx-area { fill: var(--primary-400); }
    .dark .gx-line { stroke: var(--primary-400); }

    /* Entrance: the bars grow from the base one after the other, then the income line draws from left to right */
    .gx-bar-orders,
    .gx-bar-completed {
        transform-box: fill-box;
        transform-origin: bottom;
        animation: gx-grow .5s cubic-bezier(.2, .8, .3, 1) backwards;
        animation-delay: calc(var(--gx-i, 0) * 14ms);
    }

    .gx-bar-completed {
        animation-delay: calc(var(--gx-i, 0) * 14ms + 60ms);
    }

    .gx-income {
        animation: gx-reveal .9s ease-out .2s backwards;
    }

    /* The servers' coloured squares start empty and light up one after the other, left to right */
    .gx-servers .gx-meter [role="img"] > span[data-on] {
        animation: gx-light .18s ease-out backwards;
        animation-delay: calc(var(--gx-i, 0) * 45ms + 150ms);
    }

    @keyframes gx-grow {
        from { transform: scaleY(0); }
    }

    @keyframes gx-reveal {
        from { clip-path: inset(-20% 100% -20% 0); }
        to { clip-path: inset(-20% 0 -20% 0); }
    }

    @keyframes gx-light {
        from { background: rgb(127 127 127 / .2); }
    }

    @media (prefers-reduced-motion: reduce) {
        .gx-bar-orders,
        .gx-bar-completed,
        .gx-income,
        .gx-servers .gx-meter [role="img"] > span[data-on] {
            animation: none;
        }
    }

    /* Servers */
    .gx-server-list {
        display: flex;
        flex-direction: column;
        gap: 1.1rem;
    }

    .gx-server {
        display: flex;
        flex-direction: column;
        gap: .6rem;
    }

    /* The squares fill the card: stacked on a narrow card, side by side once each still gets 22rem */
    .gx-server-bars {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 22rem), 1fr));
        gap: .6rem 1.5rem;
        align-items: start;
    }

    .gx-server-row {
        display: flex;
        align-items: baseline;
        gap: .5rem;
        min-width: 0;
    }

    /* Status from the server's monitor (Uptime Kuma, …) */
    .gx-status-dot {
        flex: none;
        align-self: center;
        width: .55rem;
        height: .55rem;
        border-radius: 999px;
        background: var(--gray-400);
    }

    .gx-status-success { background: var(--success-500); }
    .gx-status-danger { background: var(--danger-500); }
    .gx-status-warning { background: var(--warning-500); }

    /* Attention card */
    .gx-attention {
        gap: .4rem;
        padding-inline: .9rem;
    }

    .gx-attention > .gx-head {
        margin: 0 .5rem .4rem;
    }

    .gx-att {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: .7rem .6rem;
        border-radius: .9rem;
        transition: background-color .15s ease;
    }

    a.gx-att:hover {
        background: var(--theme-soft, var(--gray-50));
    }

    .dark a.gx-att:hover {
        background: var(--theme-soft, color-mix(in oklab, var(--color-white) 5%, transparent));
    }

    .gx-att-text {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        line-height: 1.35;
    }

    .gx-att-text .gx-muted {
        font-size: .78rem;
    }

    .gx-att-arrow {
        width: 1rem;
        height: 1rem;
        color: var(--theme-muted, var(--gray-400));
        transition: transform .2s ease;
    }

    a.gx-att:hover .gx-att-arrow {
        transform: translateX(3px);
    }

    .gx-support-counts {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .6rem;
        margin: 0 .5rem;
    }

    .gx-support-count {
        display: flex;
        flex-direction: column;
        gap: .2rem;
        padding: .75rem .9rem;
        border-radius: .9rem;
        background: var(--theme-soft, var(--gray-50));
    }

    .dark .gx-support-count {
        background: var(--theme-soft, color-mix(in oklab, var(--color-white) 4%, transparent));
    }

        @media (max-width: 639px) {
        .gx-big {
            font-size: 2.4rem;
        }

        .gx-hero {
            min-height: 0;
        }
    }
</style>
@endonce
