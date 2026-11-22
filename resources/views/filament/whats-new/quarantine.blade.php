{{--
    What's new in 0.12.0 (App\Support\WhatsNew): Services → Domains in miniature, the chip that opens it and two domains
    in quarantine, their grace days running out. Plain HTML rather than a picture, so every language fits and dark mode follows the panel.
--}}
<div class="gcp-wn-q" aria-hidden="true">
    <style>
        .gcp-wn-q { position: relative; padding: 1.25rem 1.25rem 0; overflow: hidden; background: linear-gradient(160deg, var(--primary-50), #fff 70%); }
        .dark .gcp-wn-q { background: linear-gradient(160deg, rgb(255 255 255 / 0.06), transparent 70%); }
        .gcp-wn-q-chips { padding-inline-end: 2rem; display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.75rem; }
        .gcp-wn-q-chip { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.625rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 600; border: 1px solid var(--gray-200); background: #fff; color: var(--gray-700); }
        .gcp-wn-q-chip.is-on { border-color: var(--primary-950, #0f2b24); background: var(--primary-950, #0f2b24); color: #fff; }
        .gcp-wn-q-chip i { width: 0.4rem; height: 0.4rem; border-radius: 9999px; background: var(--danger-500); }
        .dark .gcp-wn-q-chip { border-color: rgb(255 255 255 / 0.1); background: var(--gray-800); color: var(--gray-200); }
        .dark .gcp-wn-q-chip.is-on { border-color: var(--primary-400); background: var(--primary-500); color: var(--gray-950); }
        .gcp-wn-q-table { border-radius: 0.75rem 0.75rem 0 0; border: 1px solid var(--gray-200); border-bottom: 0; background: #fff; box-shadow: 0 10px 25px -10px rgb(0 0 0 / 0.15); }
        .dark .gcp-wn-q-table { border-color: rgb(255 255 255 / 0.1); background: var(--gray-900); box-shadow: none; }
        .gcp-wn-q-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0.875rem; border-top: 1px solid var(--gray-100); font-size: 0.8125rem; }
        .gcp-wn-q-row:first-child { border-top: 0; }
        .dark .gcp-wn-q-row { border-top-color: rgb(255 255 255 / 0.06); }
        .gcp-wn-q-row.is-q { background: color-mix(in srgb, var(--danger-50) 70%, transparent); }
        .dark .gcp-wn-q-row.is-q { background: color-mix(in srgb, var(--danger-500) 10%, transparent); }
        .gcp-wn-q-name { flex: 1; min-width: 0; font-weight: 600; color: var(--gray-950); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dark .gcp-wn-q-name { color: #fff; }
        .gcp-wn-q-bar { flex: none; width: 3.5rem; height: 0.375rem; border-radius: 9999px; background: var(--gray-100); overflow: hidden; }
        .dark .gcp-wn-q-bar { background: rgb(255 255 255 / 0.08); }
        .gcp-wn-q-bar b { display: block; height: 100%; border-radius: inherit; background: var(--danger-500); }
        .gcp-wn-q-badge svg { width: 0.75rem; height: 0.75rem; }
        .gcp-wn-q-badge { display: inline-flex; align-items: center; gap: 0.25rem; flex: none; padding: 0.125rem 0.5rem; border-radius: 0.375rem; font-size: 0.6875rem; font-weight: 600; border: 1px solid; }
        .gcp-wn-q-badge.is-q { color: var(--danger-600); border-color: var(--danger-200); background: var(--danger-50); }
        .gcp-wn-q-badge.is-ok { color: var(--success-700); border-color: var(--success-200); background: var(--success-50); }
        .dark .gcp-wn-q-badge.is-q { color: var(--danger-400); border-color: rgb(255 255 255 / 0.1); background: color-mix(in srgb, var(--danger-500) 15%, transparent); }
        .dark .gcp-wn-q-badge.is-ok { color: var(--success-400); border-color: rgb(255 255 255 / 0.1); background: color-mix(in srgb, var(--success-500) 15%, transparent); }
    </style>


    <div class="gcp-wn-q-chips">
        <span class="gcp-wn-q-chip is-on">{{ __('Active and in quarantine') }}</span>
        <span class="gcp-wn-q-chip"><i></i>{{ __('In quarantine') }}</span>
    </div>

    <div class="gcp-wn-q-table">
        <div class="gcp-wn-q-row is-q">
            <span class="gcp-wn-q-name">bakery-athens.gr</span>
            <span class="gcp-wn-q-bar"><b style="width: 80%"></b></span>
            <span class="gcp-wn-q-badge is-q"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12M6 21h12M7 3c0 5 4 6 5 9-1 3-5 4-5 9M17 3c0 5-4 6-5 9 1 3 5 4 5 9" /></svg>{{ __('Quarantine') }}</span>
        </div>
        <div class="gcp-wn-q-row is-q">
            <span class="gcp-wn-q-name">studio-north.com</span>
            <span class="gcp-wn-q-bar"><b style="width: 35%"></b></span>
            <span class="gcp-wn-q-badge is-q"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12M6 21h12M7 3c0 5 4 6 5 9-1 3-5 4-5 9M17 3c0 5-4 6-5 9 1 3 5 4 5 9" /></svg>{{ __('Quarantine') }}</span>
        </div>
        <div class="gcp-wn-q-row">
            <span class="gcp-wn-q-name">olive-press.eu</span>
            <span class="gcp-wn-q-badge is-ok">{{ __('Active') }}</span>
        </div>
    </div>
</div>
