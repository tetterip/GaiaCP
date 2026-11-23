{{--
    A table whose rows open a page (recordUrl) wraps every cell in a link, so the text of a row (a payment notice's
    line description, a client's email) could not be selected and copied: the browser drags a link instead of
    selecting in it, and Livewire's wire:navigate cancels the mouse press of a link it opens (it starts loading the
    page then). Here the cell links are not draggable, their press is left to the browser so a drag selects text, and
    the click that ends a selection inside one does not open the row. A plain click still opens it (through
    Livewire.navigate, as wire:navigate would); Ctrl / middle clicks stay the browser's. Both panels,
    PanelsRenderHook::BODY_END.
--}}
<style>
    a.fi-ta-col { -webkit-user-drag: none; -webkit-user-select: text; user-select: text; }
</style>
<script @nonce data-gcp-selectable-rows>
    (() => {
        if (window.gcpSelectableRows) return;
        window.gcpSelectableRows = true;

        const cellLink = (event) => event.target instanceof Element ? event.target.closest('a.fi-ta-col[href]') : null;
        const plainLeft = (event) => event.button === 0 && ! (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey);
        const navigates = (link) => [...link.attributes].some((attribute) => attribute.name.startsWith('wire:navigate'));
        const selectedIn = (link) => {
            const selection = window.getSelection();

            return !! selection && ! selection.isCollapsed && selection.toString().trim() !== '' && selection.containsNode(link, true);
        };

        window.addEventListener('mousedown', (event) => {
            const link = cellLink(event);
            if (! link) return;
            link.draggable = false;
            // Keeps wire:navigate's mousedown handler, which cancels the press, from running.
            if (plainLeft(event) && navigates(link)) event.stopPropagation();
        }, true);

        window.addEventListener('click', (event) => {
            const link = cellLink(event);
            if (! link || ! selectedIn(link)) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        // wire:navigate cancels the click and expected to navigate on mouseup; do it here unless something inside the
        // cell (a copy button) stopped the click first.
        document.addEventListener('click', (event) => {
            const link = cellLink(event);
            if (! link || ! event.defaultPrevented || ! plainLeft(event) || ! navigates(link)) return;
            const navigate = window.Livewire?.navigate ?? window.Alpine?.navigate;
            if (navigate) navigate(link.getAttribute('href'));
            else window.location.href = link.href;
        });
    })();
</script>
