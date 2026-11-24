{{--
    Filament's date picker (native(false)) only fills its field from the calendar: the field is read-only and
    Backspace / Delete clear the whole date. Fields that carry data-typeable-date (AppServiceProvider: every
    DatePicker that is not disabled or read-only) can be typed in as well, in that day.js display format (DD/MM/YYYY
    by default, Settings → General → Region). Any separator works (09/04/2028, 9.4.28, 9-4-2028) and so do digits
    alone (09042028, 090428); a 2-digit year is 20xx. The calendar still opens on click and follows a typed date.
    The date is saved on Enter or when leaving the field; one that is not a real date, or outside the field's
    limits, puts the saved date back and flashes the field red. An emptied field clears the date. Both panels,
    PanelsRenderHook::BODY_END.
--}}
<style>
    .fi-fo-date-time-picker-trigger[data-typeable-date] { cursor: text; }
    .fi-input-wrp:has(.gcp-date-invalid) { outline: 2px solid var(--danger-500, #ef4444); outline-offset: -1px; }
</style>
<script @nonce data-gcp-typeable-dates>
    (() => {
        if (window.gcpTypeableDates) return;
        window.gcpTypeableDates = true;

        const field = (event) => event.target instanceof HTMLInputElement && event.target.matches('[data-typeable-date]') ? event.target : null;
        const picker = (input) => window.Alpine?.$data(input);

        const editable = (event) => {
            const input = field(event);
            if (input && ! input.disabled) input.readOnly = false;
        };

        // A day.js date at midnight UTC, as the picker keeps them, or null when the text is not a real date.
        const parse = (text, format) => {
            if (! window.dayjs?.utc) return null;

            const order = format.split(/[^A-Za-z]+/).filter(Boolean).map((token) => token[0].toUpperCase());
            if (order.length !== 3 || ! ['D', 'M', 'Y'].every((token) => order.includes(token))) return null;

            let parts;
            if (/^\d+$/.test(text)) {
                if (text.length !== 6 && text.length !== 8) return null;
                const lengths = { D: 2, M: 2, Y: text.length - 4 };
                let at = 0;
                parts = order.map((token) => text.slice(at, at += lengths[token]));
            } else {
                parts = text.split(/\D+/).filter(Boolean);
                if (parts.length !== 3) return null;
            }

            const value = Object.fromEntries(order.map((token, index) => [token, parts[index]]));
            if (! [2, 4].includes(value.Y.length) || value.D.length > 2 || value.M.length > 2) return null;

            const year = value.Y.length === 2 ? 2000 + Number(value.Y) : Number(value.Y);
            const month = Number(value.M);
            const day = Number(value.D);
            const date = window.dayjs.utc(Date.UTC(year, month - 1, day));
            if (year < 1000 || date.year() !== year || date.month() !== month - 1 || date.date() !== day) return null;

            return date;
        };

        const flash = (input) => {
            input.classList.add('gcp-date-invalid');
            setTimeout(() => input.classList.remove('gcp-date-invalid'), 1500);
        };

        // Saves the typed text as the field's date, or puts the saved one back. True when the date changed.
        const commit = (input) => {
            const data = picker(input);
            if (! data?.setState) return false;

            const text = input.value.trim();
            const current = data.getSelectedDate();

            if (text === '') {
                if (current) data.clearState();

                return !! current;
            }

            const date = parse(text, input.dataset.typeableDate);
            if (! date || data.dateIsDisabled(date)) {
                data.setDisplayText();
                flash(input);

                return false;
            }

            if (current && current.isSame(date, 'day')) {
                data.setDisplayText();

                return false;
            }

            data.focusedDate = date.locale(data.focusedDate?.locale() ?? 'en');
            data.focusedMonth = date.month();
            data.focusedYear = date.year();
            data.setState(date);

            return true;
        };

        window.addEventListener('pointerdown', editable, true);
        window.addEventListener('focusin', editable, true);

        // While typing, the open calendar moves to the date typed so far.
        window.addEventListener('input', (event) => {
            const input = field(event);
            const data = input && picker(input);
            const date = data && parse(input.value.trim(), input.dataset.typeableDate);
            if (! date || ! data.focusedDate) return;
            data.focusedDate = date.locale(data.focusedDate.locale());
            data.focusedMonth = date.month();
            data.focusedYear = date.year();
        }, true);

        window.addEventListener('change', (event) => {
            const input = field(event);
            if (input) commit(input);
        }, true);

        // Runs before the picker's own keys: Backspace / Delete edit the text instead of clearing the date, the
        // left / right arrows move the cursor, Enter saves. The other keys (down arrow, space) still open the calendar.
        window.addEventListener('keydown', (event) => {
            const input = field(event);
            if (! input || input.readOnly || event.altKey || event.ctrlKey || event.metaKey) return;

            if (['Backspace', 'Delete', 'Clear', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.stopPropagation();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                commit(input);
                const data = picker(input);
                if (data?.isOpen?.()) data.closePanel(true);
            } else if (event.key === 'Escape') {
                picker(input)?.setDisplayText();
            }
        }, true);
    })();
</script>
