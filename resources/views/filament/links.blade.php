{{--
    Links written into texts (helper texts, section descriptions, Text components, modal and callout descriptions,
    rendered Markdown) look like links: Filament leaves a bare <a> unstyled and no custom theme is compiled, so a
    class such as "underline" in those texts does nothing. Filament's own link components (fi-* classes) keep their
    look. A click on such a link to another site opens it in a new tab. Both panels, PanelsRenderHook::HEAD_END.
--}}
<style>
    :where(.fi-sc-text, .fi-section-header-description, .fi-modal-description, .fi-callout-description, .fi-empty-state-description, .fi-fo-checkbox-list-option-description, .fi-ta-header-description, .fi-prose, .gcp-help) a:not([class*="fi-"]) {
        color: var(--primary-600);
        font-weight: 500;
        text-decoration: underline;
        text-underline-offset: 2px;

        &:hover { color: var(--primary-500); }

        .dark & { color: var(--primary-400); }

        .dark &:hover { color: var(--primary-300); }
    }
</style>
<script>
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]:not([target])');

        if (! link || link.matches('[class*="fi-"]') || ! link.closest('.fi-sc-text, .fi-section-header-description, .fi-modal-description, .fi-callout-description, .fi-empty-state-description, .fi-fo-checkbox-list-option-description, .fi-ta-header-description, .fi-prose, .gcp-help')) {
            return;
        }

        if (/^https?:$/.test(link.protocol) && link.host !== window.location.host) {
            link.target = '_blank';
            link.rel = 'noopener';
        }
    });
</script>
