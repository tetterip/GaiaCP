{{--
    Secondary text in dark mode (WCAG 1.4.3): our own views, and some of Filament's, use the grey 500 for hints, dates,
    footers and small headings in both modes. The themes darken 500 so it passes on light pages; on dark pages that
    same grey falls to 3.6-4:1, so dark mode reads 500 as 400 (6-7:1). Both panels, PanelsRenderHook::HEAD_END, after
    the theme's styles.
--}}
<style>
    html.dark {
        --gray-500: var(--gray-400);
    }
</style>
