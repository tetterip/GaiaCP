{{-- The admin login's other ways in (QuickSignIn), in the look of the client area's (filament.themes.client). --}}
<style>
    .gcp-signin { display: grid; gap: 1rem; }
    .gcp-signin-or { display: flex; align-items: center; gap: .75rem; font-size: .875rem; color: var(--gray-500); }
    .gcp-signin-or::before, .gcp-signin-or::after { content: ""; flex: 1; height: 1px; background: var(--gray-200); }
    .dark .gcp-signin-or::before, .dark .gcp-signin-or::after { background: var(--gray-700); }
    .gcp-signin-button {
        position: relative; display: flex; align-items: center; justify-content: center; gap: .75rem; width: 100%;
        padding: .5rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-200); background: var(--color-white, #fff);
        color: var(--gray-950); font-size: .875rem; font-weight: 600; cursor: pointer; transition: background-color .15s;
    }
    .gcp-signin-button:hover { background: var(--gray-50); }
    .dark .gcp-signin-button { border-color: var(--gray-700); background: var(--gray-900); color: var(--color-white, #fff); }
    .dark .gcp-signin-button:hover { background: var(--gray-800); }
    .gcp-signin-button svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
    .gcp-signin-last {
        position: absolute; top: -.625rem; inset-inline-end: .5rem; padding: 0 .5rem; border: 1px solid var(--gray-200);
        border-radius: 999px; background: var(--color-white, #fff); color: var(--gray-500); font-size: .75rem; font-weight: 500; line-height: 1.25rem;
    }
    .dark .gcp-signin-last { border-color: var(--gray-700); background: var(--gray-900); color: var(--gray-400); }
    .gcp-signin-pending { display: grid; gap: 1rem; text-align: center; }
</style>
