@php
    use App\Support\WelcomeTour;

    $steps = WelcomeTour::steps();
    $finish = WelcomeTour::finish();
@endphp

{{--
    The welcome tour (App\Support\WelcomeTour): opens by itself on the first visit, and from the user menu → Tour. Each
    card lights up what it is about (a menu item, a menu group, part of the top bar) and sits beside it; on a phone the
    side menu opens for the cards about its items and the card sits at the top or the foot of the screen. A card whose
    target isn't on the page (hidden, or a plugin's menu that is off) shows in the middle, like the first one.
--}}
<div
    x-data="{
        open: false,
        step: 0,
        last: {{ count($steps) - 1 }},
        targets: @js(array_column($steps, 'target')),
        spot: null,
        card: '',
        openedSidebar: false,

        init() {
            window.addEventListener('resize', () => this.place())
            window.addEventListener('scroll', () => this.place(), true)

            if (this.$el.dataset.opensNow === 'yes') {
                this.$nextTick(() => this.start())
            }
        },

        start() {
            this.open = true
            this.go(0)
        },

        go(index) {
            this.step = index
            this.place(true)
        },

        close() {
            this.open = false
            this.spot = null

            if (this.openedSidebar) {
                Alpine.store('sidebar').close()
                this.openedSidebar = false
            }
        },

        find(target) {
            if (! target) {
                return null
            }

            const nav = target.match(/^nav(group)?:(.+)$/)

            if (! nav) {
                return document.querySelector(target)
            }

            const link = [...document.querySelectorAll('.fi-sidebar-item a')].find((a) => {
                const href = a.getAttribute('href') || ''

                return href === nav[2] || href.startsWith(nav[2] + '?')
            })

            return link ? link.closest(nav[1] ? '.fi-sidebar-group' : '.fi-sidebar-item') : null
        },

        place(scroll = false) {
            if (! this.open) {
                return
            }

            const el = this.find(this.targets[this.step])
            const narrow = window.innerWidth < 1024
            const sidebar = Alpine.store('sidebar')

            if (el && el.closest('.fi-sidebar') && narrow && ! sidebar.isOpen) {
                sidebar.open()
                this.openedSidebar = true
                setTimeout(() => this.place(true), 350)

                return
            }

            // Whoever opened the side menu (the tour, or the admin to reach Tour in it), it hides the top bar on a phone
            if (narrow && sidebar.isOpen && ! (el && el.closest('.fi-sidebar'))) {
                sidebar.close()
                this.openedSidebar = false
                setTimeout(() => this.place(true), 350)

                return
            }

            if (el && scroll) {
                el.scrollIntoView({ block: 'nearest' })
            }

            requestAnimationFrame(() => this.measure(el))
        },

        measure(el) {
            const rect = el ? el.getBoundingClientRect() : null
            const vw = window.innerWidth
            const vh = window.innerHeight
            const width = Math.min(352, vw - 32)
            const height = this.$refs.card ? this.$refs.card.offsetHeight : 240

            if (! rect || ! rect.width || ! rect.height) {
                this.spot = null
                this.card = `width: ${width}px; left: ${(vw - width) / 2}px; top: ${Math.max(16, (vh - height) / 2)}px`

                return
            }

            const pad = 6
            this.spot = { top: rect.top - pad, left: rect.left - pad, width: rect.width + pad * 2, height: rect.height + pad * 2 }

            const clamp = (value, min, max) => Math.max(min, Math.min(value, max))

            if (vw < 640) {
                // A phone: the card goes to the half of the screen away from what it points at
                const top = rect.top + rect.height / 2 > vh / 2 ? 16 : vh - height - 16
                this.card = `width: ${width}px; left: 16px; top: ${top}px`
            } else if (rect.right + 16 + width <= vw - 16) {
                // Beside it (the side menu)
                this.card = `width: ${width}px; left: ${rect.right + 16}px; top: ${clamp(rect.top, 16, vh - height - 16)}px`
            } else {
                // Under it (the top bar)
                this.card = `width: ${width}px; left: ${clamp(rect.right - width, 16, vw - width - 16)}px; top: ${clamp(rect.bottom + 12, 16, vh - height - 16)}px`
            }
        },

        // The dimmed screen around the lit part, in four pieces (a big box-shadow doesn't paint over the panel)
        shade(side) {
            const s = this.spot

            if (! s) {
                return 'display: none'
            }

            return {
                top: `top: 0; left: 0; right: 0; height: ${Math.max(0, s.top)}px`,
                bottom: `top: ${s.top + s.height}px; left: 0; right: 0; bottom: 0`,
                left: `top: ${s.top}px; left: 0; width: ${Math.max(0, s.left)}px; height: ${s.height}px`,
                right: `top: ${s.top}px; left: ${s.left + s.width}px; right: 0; height: ${s.height}px`,
            }[side]
        },

        ring() {
            const s = this.spot

            return s ? `top: ${s.top}px; left: ${s.left}px; width: ${s.width}px; height: ${s.height}px` : 'display: none'
        },
    }"
    data-opens-now="{{ $opensNow ? 'yes' : 'no' }}"
    x-on:open-welcome-tour.window="start()"
    x-on:keydown.escape.window="if (open) close()"
    x-on:keydown.arrow-right.window="if (open && step < last) go(step + 1)"
    x-on:keydown.arrow-left.window="if (open && step > 0) go(step - 1)"
>
    <style>
        .gcp-tour-catcher { position: fixed; inset: 0; z-index: 60; transition: background-color .2s; }
        .gcp-tour-catcher.is-dim { background: rgb(0 0 0 / 0.5); }
        .gcp-tour-shade { position: fixed; z-index: 61; background: rgb(0 0 0 / 0.5); pointer-events: none; }
        .gcp-tour-spot { position: fixed; z-index: 61; border-radius: 0.75rem; pointer-events: none; outline: 3px solid var(--primary-400); }
        .gcp-tour-panel { position: fixed; z-index: 62; max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 0.75rem; padding: 1.25rem 1.5rem; background: #fff; color: var(--gray-950); box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.25); transition: top .25s ease, left .25s ease; }
        .dark .gcp-tour-panel { background: var(--gray-900); color: #fff; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.5), 0 0 0 1px rgb(255 255 255 / 0.1); }
        .gcp-tour-close { position: absolute; top: 0.75rem; inset-inline-end: 0.75rem; padding: 0.25rem; border-radius: 0.375rem; color: var(--gray-400); }
        .gcp-tour-close:hover { color: var(--gray-600); }
        .gcp-tour-head { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; padding-inline-end: 1.5rem; }
        .gcp-tour-icon { display: flex; flex: none; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 9999px; background: var(--primary-50); color: var(--primary-600); }
        .dark .gcp-tour-icon { background: rgb(255 255 255 / 0.05); color: var(--primary-400); }
        .gcp-tour-panel h2 { font-size: 1rem; font-weight: 600; }
        .gcp-tour-text { font-size: 0.875rem; line-height: 1.5; color: var(--gray-600); }
        .dark .gcp-tour-text { color: var(--gray-300); }
        .gcp-tour-link { display: inline-block; margin-top: 0.5rem; font-size: 0.875rem; font-weight: 600; color: var(--primary-600); }
        .dark .gcp-tour-link { color: var(--primary-400); }
        .gcp-tour-link:hover { text-decoration: underline; }
        .gcp-tour-foot { display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem; }
        .gcp-tour-dots { display: flex; gap: 0.375rem; margin-inline-end: auto; }
        .gcp-tour-dots button { width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--gray-300); }
        .dark .gcp-tour-dots button { background: var(--gray-600); }
        .gcp-tour-dots button.is-on { background: var(--primary-600); }
    </style>

    <template x-teleport="body">
        <div x-show="open" style="display: none" role="dialog" aria-modal="true" aria-labelledby="gcp-tour-title">
            {{-- Keeps clicks off the page while the tour is open --}}
            <div class="gcp-tour-catcher" x-bind:class="{ 'is-dim': ! spot }"></div>
            @foreach (['top', 'bottom', 'left', 'right'] as $side)
                <div class="gcp-tour-shade" x-bind:style="shade('{{ $side }}')"></div>
            @endforeach
            <div class="gcp-tour-spot" x-bind:style="ring()"></div>

            <div class="gcp-tour-panel" x-ref="card" x-bind:style="card">
                <button type="button" class="gcp-tour-close" x-on:click="close()" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>

                @foreach ($steps as $i => $item)
                    <div x-show="step === {{ $i }}" @if ($i > 0) style="display: none" @endif>
                        <div class="gcp-tour-head">
                            <span class="gcp-tour-icon">
                                <x-filament::icon :icon="$item['icon']" class="h-5 w-5" />
                            </span>
                            <h2 @if ($i === 0) id="gcp-tour-title" @endif>{{ $item['title'] }}</h2>
                        </div>
                        <p class="gcp-tour-text">{{ $item['text'] }}</p>
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="gcp-tour-link" x-on:click="close()">{{ $item['link'] }} →</a>
                        @endif
                    </div>
                @endforeach

                <div class="gcp-tour-foot">
                    <div class="gcp-tour-dots">
                        @foreach ($steps as $i => $item)
                            <button type="button" x-on:click="go({{ $i }})" x-bind:class="{ 'is-on': step === {{ $i }} }" aria-label="{{ $item['title'] }}"></button>
                        @endforeach
                    </div>

                    <x-filament::button color="gray" size="sm" outlined x-show="step > 0" x-on:click="go(step - 1)">
                        {{ __('Back') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" outlined x-show="step === 0" x-on:click="close()">
                        {{ __('Skip') }}
                    </x-filament::button>
                    <x-filament::button size="sm" x-show="step < last" x-on:click="go(step + 1)">
                        {{ __('Next') }}
                    </x-filament::button>
                    <div x-show="step === last" style="display: none">
                        @if ($finish['url'])
                            <x-filament::button size="sm" tag="a" :href="$finish['url']">{{ $finish['label'] }}</x-filament::button>
                        @else
                            <x-filament::button size="sm" x-on:click="close()">{{ $finish['label'] }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
