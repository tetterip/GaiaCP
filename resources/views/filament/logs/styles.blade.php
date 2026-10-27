{{-- Shared look of the log viewers' modals (module log, email log, error log). --}}
<style>
    .gaiacp-log { display: grid; gap: 1rem; font-size: 0.875rem; }
    .gaiacp-log-meta { color: var(--gray-500); overflow-wrap: anywhere; }
    .gaiacp-log-label { font-weight: 500; margin-bottom: 0.25rem; }
    .gaiacp-log pre {
        max-height: 24rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; margin: 0;
        padding: 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; line-height: 1.5;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        background: var(--gray-50); color: var(--gray-950);
    }
    .gaiacp-log-error { padding: 0.75rem; border-radius: 0.5rem; background: var(--danger-50); color: var(--danger-700); overflow-wrap: anywhere; }
    .dark .gaiacp-log pre { background: rgb(255 255 255 / 0.05); color: var(--gray-200); }
    .dark .gaiacp-log-error { background: rgb(239 68 68 / 0.12); color: var(--danger-300); }
    .gaiacp-log iframe { width: 100%; height: 60vh; border: 0; border-radius: 0.5rem; background: #fff; }
</style>
