{{--
    Filament keeps a table's toolbar action row even when it has nothing in it (no reorder, toolbar or grouping
    actions); on a phone that row stacks over the search as an empty band. Admin panel, PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-ta-header-toolbar > .fi-ta-actions:not(:has(*)) {
        display: none;
    }
</style>
