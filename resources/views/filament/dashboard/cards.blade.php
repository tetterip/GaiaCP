{{-- The dashboard's cards (App\Filament\Pages\Dashboard): the day's numbers, then a row across the top (column 0, where
     Needs attention starts) over two columns of the same width on a wide screen, each filling down on its own so no card
     leaves a gap under it; a phone shows the top row, the first column, then the second. Each card has a handle on top:
     drag it within or between the three (Filament's x-sortable, SortableJS, one shared group) or press the arrow keys on
     it; the new layout is saved for this admin (App\Support\DashboardLayout::reorder()). --}}
@include('filament.dashboard.styles')
@include('filament.dashboard.today', ['boxes' => $today])
<div
    class="gx-dash"
    x-data="{
        dragging: false,
        column(number) {
            return this.$root.querySelector(`[data-gx-column='${number}']`)
        },
        save() {
            $wire.reorderDashboard({
                0: this.column(0).sortable.toArray(),
                1: this.column(1).sortable.toArray(),
                2: this.column(2).sortable.toArray(),
            })
        },
        move(slot, key) {
            const column = slot.parentElement
            const top = this.column(0)
            if (key === 'up' || key === 'down') {
                const other = key === 'up' ? slot.previousElementSibling : slot.nextElementSibling
                if (other) {
                    key === 'up' ? other.before(slot) : other.after(slot)
                } else if (key === 'up' && column !== top) {
                    top.append(slot) // past the top of a column: into the row across the top
                } else if (key === 'down' && column === top) {
                    this.column(1).prepend(slot)
                } else {
                    return
                }
            } else {
                const target = this.column(key === 'left' ? 1 : 2)
                if (target === column || column === top) return
                const index = [...column.children].indexOf(slot)
                target.insertBefore(slot, target.children[index] ?? null)
            }
            slot.querySelector('[x-sortable-handle]').focus()
            this.save()
        },
    }"
    x-bind:class="{ 'gx-dragging': dragging }"
    x-on:start="if ($event.target.matches('.gx-col')) dragging = true"
    x-on:end.stop="if ($event.target.matches('.gx-col')) { dragging = false; save() }"
>
    @foreach ($columns as $column => $cards)
        <div
            @class(['gx-col', 'gx-top' => $column === 0])
            data-gx-column="{{ $column }}"
            x-sortable
            x-sortable-group="gx-dashboard"
            x-bind:data-sortable-animation-duration="window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180"
        >
            @foreach ($cards as $key => $card)
                <div class="gx-slot" x-sortable-item="{{ $key }}" wire:key="gx-slot-{{ $key }}">
                    <button
                        type="button"
                        class="gx-grip"
                        x-sortable-handle
                        aria-label="{{ __('Move :card', ['card' => $card['label']]) }}"
                        title="{{ __('Drag to move the card (or use the arrow keys)') }}"
                        x-on:keydown.up.prevent="move($el.parentElement, 'up')"
                        x-on:keydown.down.prevent="move($el.parentElement, 'down')"
                        x-on:keydown.left.prevent="move($el.parentElement, 'left')"
                        x-on:keydown.right.prevent="move($el.parentElement, 'right')"
                    >
                        <svg viewBox="0 0 20 8" aria-hidden="true"><circle cx="4" cy="2" r="1.4"/><circle cx="10" cy="2" r="1.4"/><circle cx="16" cy="2" r="1.4"/><circle cx="4" cy="6" r="1.4"/><circle cx="10" cy="6" r="1.4"/><circle cx="16" cy="6" r="1.4"/></svg>
                    </button>
                    {!! \Livewire\Livewire::mount($card['class'], $card['data'], $key) !!}
                </div>
            @endforeach
        </div>
    @endforeach
</div>
