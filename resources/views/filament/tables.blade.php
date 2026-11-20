{{--
    Filament keeps a table's toolbar action row even when it has nothing in it (no reorder, toolbar or grouping
    actions); on a phone that row stacks over the search as an empty band. A grouped table laid out in rows (Split, e.g.
    Settings → Plugins) gives each group its own select checkbox, so the select-page checkbox above the first group,
    alone in its row, is a second checkbox doing much the same: hidden. An empty list that is good news (icon
    gcp-good-news: no tickets waiting, no failed jobs) shows a green tick on a light green circle. Admin panel,
    PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-ta-empty-state-icon-bg:has([data-gcp-good-news]) { background: var(--success-50); }
    .fi-ta-empty-state-icon-bg [data-gcp-good-news] { color: var(--success-600); }
    .dark .fi-ta-empty-state-icon-bg:has([data-gcp-good-news]) { background: color-mix(in oklab, var(--success-500) 15%, transparent); }
    .dark .fi-ta-empty-state-icon-bg [data-gcp-good-news] { color: var(--success-400); }
    .fi-ta-header-toolbar > .fi-ta-actions:not(:has(*)) {
        display: none;
    }

    .fi-ta-content-ctn:has(.fi-ta-group-checkbox) .fi-ta-content-header:not(:has(> :not(.fi-ta-page-checkbox))) {
        display: none;
    }
</style>
