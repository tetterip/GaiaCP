{{-- Swipe a notification left or right to remove it, on a phone or tablet: in the bell's list it is deleted (the same
     as its X), a pop-up one is closed. Touch only: a mouse keeps the X. A short or mostly vertical move scrolls as before
     and the notification springs back. --}}
<script>
    (() => {
        if (window['gaiacp-notification-swipe']) {
            return;
        }
        window['gaiacp-notification-swipe'] = true;

        const still = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let swipe = null;

        const reset = (el) => {
            el.style.transition = '';
            el.style.transform = '';
            el.style.opacity = '';
            el.style.touchAction = '';
        };

        const close = (el) => {
            const component = window.Alpine?.$data(el);
            if (typeof component?.close === 'function') {
                component.close();
            }
        };

        // A swipe that started on a link or button must not also open it
        const swallowNextClick = () => {
            const stop = (event) => {
                event.preventDefault();
                event.stopPropagation();
            };
            document.addEventListener('click', stop, { capture: true, once: true });
            setTimeout(() => document.removeEventListener('click', stop, { capture: true }), 400);
        };

        document.addEventListener('touchstart', (event) => {
            const el = event.target.closest?.('.fi-no-notification');
            if (! el || event.touches.length !== 1 || el.dataset.swiped) {
                swipe = null;
                return;
            }

            const touch = event.touches[0];
            swipe = { el, x: touch.clientX, y: touch.clientY, dx: 0, time: performance.now(), moving: false };
        }, { passive: true });

        document.addEventListener('touchmove', (event) => {
            if (! swipe) {
                return;
            }

            const touch = event.touches[0];
            const dx = touch.clientX - swipe.x;
            const dy = touch.clientY - swipe.y;

            if (! swipe.moving) {
                if (Math.abs(dy) > 10 && Math.abs(dy) >= Math.abs(dx)) {
                    swipe = null; // scrolling the list
                    return;
                }
                if (Math.abs(dx) < 10) {
                    return;
                }
                swipe.moving = true;
                swipe.el.style.transition = 'none';
            }

            event.preventDefault(); // no scrolling or browser back gesture while a notification follows the finger
            swipe.dx = dx;
            const width = swipe.el.offsetWidth || 1;
            swipe.el.style.transform = `translateX(${dx}px)`;
            swipe.el.style.opacity = String(Math.max(0.2, 1 - Math.abs(dx) / width));
        }, { passive: false });

        const end = () => {
            if (! swipe?.moving) {
                swipe = null;
                return;
            }

            const { el, dx, time } = swipe;
            swipe = null;
            swallowNextClick();

            const width = el.offsetWidth || 1;
            const fast = Math.abs(dx) > 40 && Math.abs(dx) / (performance.now() - time) > 0.5;
            if (Math.abs(dx) < width * 0.35 && ! fast) {
                el.style.transition = still() ? '' : 'transform 200ms ease, opacity 200ms ease';
                el.style.transform = '';
                el.style.opacity = '';
                setTimeout(() => reset(el), 220);
                return;
            }

            el.dataset.swiped = '1';
            if (still()) {
                close(el);
                return;
            }

            el.style.transition = 'transform 180ms ease-out, opacity 180ms ease-out';
            el.style.transform = `translateX(${dx > 0 ? width : -width}px)`;
            el.style.opacity = '0';

            // In the bell's list the row then folds away, so the ones below move up instead of jumping
            const row = el.closest('.fi-no-notifications > [role="listitem"]');
            setTimeout(() => {
                if (row) {
                    row.style.overflow = 'hidden';
                    row.animate([{ height: `${row.offsetHeight}px` }, { height: '0px' }], { duration: 160, easing: 'ease-in', fill: 'forwards' });
                    setTimeout(() => close(el), 160);
                } else {
                    close(el);
                }
            }, 180);
        };

        document.addEventListener('touchend', end, { passive: true });
        document.addEventListener('touchcancel', () => {
            if (swipe?.moving) {
                reset(swipe.el);
            }
            swipe = null;
        }, { passive: true });
    })();
</script>
