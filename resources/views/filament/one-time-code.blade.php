{{--
    The 6-digit code of two-factor sign-in, centred on the sign-in pages (the card's title and text are centred too);
    Filament keeps the label, boxes and "Use a recovery code instead" to the left. Both panels, PanelsRenderHook::HEAD_END.
--}}
<style>
    .fi-simple-main .fi-fo-field:has(.fi-one-time-code-input-ctn) {
        text-align: center;
    }

    .fi-simple-main .fi-fo-field:has(.fi-one-time-code-input-ctn) :is(.fi-fo-field-label-ctn, .fi-fo-field-content-col, .fi-fo-field-content-col > *) {
        justify-content: center;
        justify-items: center;
    }

    .fi-simple-main .fi-one-time-code-input-ctn {
        margin-inline: auto;
    }
</style>

{{-- The first code box is focused as soon as the two-factor step of a sign-in page shows (not in windows). --}}
<script>
    (() => {
        const focused = new WeakSet()

        const focusCode = () => {
            const box = document.querySelector('.fi-simple-main .fi-one-time-code-input-ctn input')
            const active = document.activeElement

            if (! box || focused.has(box) || box.value || (active && active !== box && active.matches('input:not([type=hidden]), textarea, select'))) {
                return
            }

            focused.add(box)
            box.focus()
        }

        document.addEventListener('DOMContentLoaded', () => {
            focusCode()
            new MutationObserver(focusCode).observe(document.body, { childList: true, subtree: true })
        })
    })()
</script>
