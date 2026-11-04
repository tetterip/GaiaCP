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
