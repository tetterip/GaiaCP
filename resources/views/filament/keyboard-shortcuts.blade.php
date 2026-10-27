@php
    use App\Filament\KeyboardShortcuts;
@endphp

{{-- Keyboard shortcuts for the admin (App\Filament\KeyboardShortcuts); "?" opens this list. --}}
<div
    x-data="{
        open: false,
        prefix: null,
        timer: null,
        urls: @js(KeyboardShortcuts::urls()),

        typing(event) {
            const el = event.target

            return el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
        },

        handle(event) {
            if (event.key === 'Escape') {
                this.open = false
                this.prefix = null

                return
            }

            if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.metaKey || event.altKey || this.typing(event)) {
                return
            }

            if (event.key === '?') {
                event.preventDefault()
                this.open = ! this.open

                return
            }

            if (event.key === '/') {
                const search = document.querySelector('.fi-global-search-field input')

                if (search) {
                    event.preventDefault()
                    this.open = false
                    search.focus()
                }

                return
            }

            const key = event.key.toLowerCase()

            if (this.prefix) {
                const url = this.urls[this.prefix + key]
                this.prefix = null
                clearTimeout(this.timer)

                if (url) {
                    event.preventDefault()
                    window.location.href = url
                }

                return
            }

            if (key === 'g' || key === 'n') {
                this.prefix = key
                clearTimeout(this.timer)
                this.timer = setTimeout(() => this.prefix = null, 1500)
            }
        },
    }"
    x-on:keydown.window="handle($event)"
    x-on:open-keyboard-shortcuts.window="open = true"
    class="gaiacp-shortcuts"
>
    <style>
        .gaiacp-shortcuts-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgb(0 0 0 / 0.5); }
        .gaiacp-shortcuts-panel { width: 100%; max-width: 34rem; max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 0.75rem; padding: 1.25rem 1.5rem; background: #fff; color: var(--gray-950); box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.2); }
        .dark .gaiacp-shortcuts-panel { background: var(--gray-900); color: #fff; }
        .gaiacp-shortcuts-panel h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.75rem; }
        .gaiacp-shortcuts-panel h3 { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); margin: 1rem 0 0.25rem; }
        .gaiacp-shortcuts-panel li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.3rem 0; font-size: 0.875rem; }
        .gaiacp-shortcuts-panel kbd { display: inline-block; min-width: 1.5rem; padding: 0.1rem 0.4rem; border-radius: 0.375rem; border: 1px solid var(--gray-300); background: var(--gray-50); font-family: ui-monospace, monospace; font-size: 0.75rem; text-align: center; }
        .dark .gaiacp-shortcuts-panel kbd { border-color: var(--gray-700); background: var(--gray-800); }
        .gaiacp-shortcuts-panel .then { margin: 0 0.25rem; font-size: 0.75rem; color: var(--gray-500); }
        .gaiacp-shortcuts-columns { display: grid; gap: 0 2rem; }
        @media (min-width: 640px) { .gaiacp-shortcuts-columns { grid-template-columns: 1fr 1fr; } }
    </style>

    <template x-teleport="body">
        <div
            x-show="open"
            x-transition.opacity
            x-on:click.self="open = false"
            class="gaiacp-shortcuts-backdrop"
            style="display: none"
            {{-- on the element x-show hides: Filament skips its own shortcuts while a visible aria-modal is open --}}
            role="dialog"
            aria-modal="true"
            aria-labelledby="gaiacp-shortcuts-title"
        >
            <div class="gaiacp-shortcuts-panel">
                <h2 id="gaiacp-shortcuts-title">{{ __('Keyboard shortcuts') }}</h2>

                <div class="gaiacp-shortcuts-columns">
                    @foreach (KeyboardShortcuts::sequences() as $group => $items)
                        <div>
                            <h3>{{ __($group) }}</h3>
                            <ul>
                                @foreach ($items as $keys => [$label])
                                    <li>
                                        <span>{{ __($label) }}</span>
                                        <span>
                                            @foreach (explode(' ', $keys) as $key)
                                                @if (! $loop->first)<span class="then">{{ __('then') }}</span>@endif<kbd>{{ $key }}</kbd>
                                            @endforeach
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($loop->last)
                                <h3>{{ __('General') }}</h3>
                                <ul>
                                    @foreach (KeyboardShortcuts::general() as $keys => $label)
                                        <li>
                                            <span>{{ __($label) }}</span>
                                            <span><kbd>{{ $keys }}</kbd></span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </template>
</div>
