{{--
    Filament keeps a table's toolbar action row even when it has nothing in it (no reorder, toolbar or grouping
    actions); on a phone that row stacks over the search as an empty band. A grouped table laid out in rows (Split, e.g.
    Settings → Plugins) gives each group its own select checkbox, so the select-page checkbox above the first group,
    alone in its row, is a second checkbox doing much the same: hidden. Admin panel, PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-ta-header-toolbar > .fi-ta-actions:not(:has(*)) {
        display: none;
    }

    .fi-ta-content-ctn:has(.fi-ta-group-checkbox) .fi-ta-content-header:not(:has(> :not(.fi-ta-page-checkbox))) {
        display: none;
    }
</style>
