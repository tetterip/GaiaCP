{{-- Swipe a notification left or right to remove it, with a finger or by dragging it with the mouse or a trackpad: in
     the bell's list it is deleted (the same as its X), a pop-up one is closed. Pointer events, so touch and mouse share
     one path. The notifications next to the one being dragged are pulled along a little (an elastic, damped offset that
     fades with distance) and spring back when it is let go. A short or mostly vertical move scrolls as before and the
     notification springs back. The X stays for the keyboard and screen readers; reduced motion drops the animations. --}}
<style>
    /* Horizontal moves on a touch screen come to the script below; vertical ones still scroll the list */
    .fi-no-notification { touch-action: pan-y; }
    html.gaiacp-notification-dragging, html.gaiacp-notification-dragging * { user-select: none !important; cursor: grabbing !important; }
</style>
<script @nonce>
    (() => {
        if (window['gaiacp-notification-swipe']) {
            return;
        }
        window['gaiacp-notification-swipe'] = true;

        const still = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const SPRING = 'cubic-bezier(0.22, 1.12, 0.36, 1)'; // settles with a barely visible overshoot
        const PULL = [0.07, 0.025]; // how much of the drag the first and second neighbour follow: a hint, not a tug
        const PULL_MAX = 10; // px: the furthest a neighbour is pulled, approached softly
        let swipe = null;

        const close = (el) => {
            const component = window.Alpine?.$data(el);
            if (typeof component?.close === 'function') {
                component.close();
            }
        };

        // The notifications above and below, nearest first: rows of the bell's list, or the other pop-ups
        const neighbours = (el) => {
            const item = el.closest('[role="listitem"]') || el;
            const siblings = Array.from(item.parentElement?.children || []);
            const index = siblings.indexOf(item);
            const near = [];
            for (let distance = 1; distance <= PULL.length; distance++) {
                for (const sibling of [siblings[index - distance], siblings[index + distance]]) {
                    const notification = sibling?.matches('.fi-no-notification') ? sibling : sibling?.querySelector('.fi-no-notification');
                    if (notification && ! notification.dataset.swiped) {
                        near.push({ el: notification, pull: PULL[distance - 1] });
                    }
                }
            }
            return near;
        };

        // Damped: follows the drag at first, then gives less and less, never past PULL_MAX
        const pulled = (dx, pull) => PULL_MAX * Math.tanh((dx * pull) / PULL_MAX);

        const springBack = (el, ms = 320) => {
            el.style.transition = still() ? '' : `transform ${ms}ms ${SPRING}, opacity 200ms ease`;
            el.style.transform = '';
            el.style.opacity = '';
            setTimeout(() => {
                if (! swipe || swipe.el !== el) {
                    el.style.transition = '';
                }
            }, ms + 20);
        };

        // A drag that started on a link or button must not also open it
        const swallowNextClick = () => {
            const stop = (event) => {
                event.preventDefault();
                event.stopPropagation();
            };
            document.addEventListener('click', stop, { capture: true, once: true });
            setTimeout(() => document.removeEventListener('click', stop, { capture: true }), 400);
        };

        // Links and images inside a notification would otherwise start the browser's own drag with the mouse
        document.addEventListener('dragstart', (event) => {
            if (event.target.closest?.('.fi-no-notification')) {
                event.preventDefault();
            }
        });

        document.addEventListener('pointerdown', (event) => {
            const el = event.target.closest?.('.fi-no-notification');
            if (! el || ! event.isPrimary || event.button !== 0 || el.dataset.swiped || event.target.closest('input, textarea, select')) {
                swipe = null;
                return;
            }

            swipe = {
                el,
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                dx: 0,
                samples: [{ x: event.clientX, t: performance.now() }],
                moving: false,
                near: [],
            };
        });

        document.addEventListener('pointermove', (event) => {
            if (! swipe || event.pointerId !== swipe.id) {
                return;
            }

            const dx = event.clientX - swipe.x;
            const dy = event.clientY - swipe.y;

            if (! swipe.moving) {
                if (Math.abs(dy) > 10 && Math.abs(dy) >= Math.abs(dx)) {
                    swipe = null; // scrolling the list, or selecting text with the mouse
                    return;
                }
                if (Math.abs(dx) < 10) {
                    return;
                }
                swipe.moving = true;
                swipe.near = still() ? [] : neighbours(swipe.el);
                swipe.el.style.transition = 'none';
                swipe.near.forEach(({ el }) => { el.style.transition = 'transform 120ms ease-out'; });
                try {
                    swipe.el.setPointerCapture(event.pointerId);
                } catch {}
                window.getSelection?.()?.removeAllRanges();
                document.documentElement.classList.add('gaiacp-notification-dragging');
            }

            event.preventDefault();
            swipe.dx = dx;
            swipe.samples.push({ x: event.clientX, t: performance.now() });
            if (swipe.samples.length > 5) {
                swipe.samples.shift();
            }

            const width = swipe.el.offsetWidth || 1;
            swipe.el.style.transform = `translateX(${dx}px)`;
            // Stays solid while it follows the pointer and only fades in the last stretch, as it leaves
            swipe.el.style.opacity = String(Math.min(1, Math.max(0.5, 1.5 - (1.2 * Math.abs(dx)) / width)));
            swipe.near.forEach(({ el, pull }) => { el.style.transform = `translateX(${pulled(dx, pull)}px)`; });
        }, { passive: false });

        const stopDragging = () => document.documentElement.classList.remove('gaiacp-notification-dragging');

        const end = (event) => {
            if (! swipe || event.pointerId !== swipe.id) {
                return;
            }
            if (! swipe.moving) {
                swipe = null;
                return;
            }

            const { el, dx, samples, near } = swipe;
            swipe = null;
            stopDragging();
            swallowNextClick();

            // Speed over the last few moves, so a quick flick counts even if short
            const first = samples[0];
            const last = samples[samples.length - 1];
            const speed = Math.abs(last.x - first.x) / Math.max(1, last.t - first.t);
            const width = el.offsetWidth || 1;
            const fast = Math.abs(dx) > 40 && speed > 0.5;

            near.forEach(({ el: neighbour }) => springBack(neighbour, 360));

            if (Math.abs(dx) < width * 0.35 && ! fast) {
                springBack(el);
                return;
            }

            el.dataset.swiped = '1';
            if (still()) {
                close(el);
                return;
            }

            el.style.transition = 'transform 220ms cubic-bezier(0.2, 0, 0, 1), opacity 220ms ease-out';
            el.style.transform = `translateX(${dx > 0 ? width : -width}px)`;
            el.style.opacity = '0';

            // In the bell's list the row then folds away, so the ones below move up instead of jumping
            const row = el.closest('.fi-no-notifications > [role="listitem"]');
            setTimeout(() => {
                if (row) {
                    row.style.overflow = 'hidden';
                    row.animate([{ height: `${row.offsetHeight}px` }, { height: '0px' }], { duration: 220, easing: 'cubic-bezier(0.2, 0, 0, 1)', fill: 'forwards' });
                    setTimeout(() => close(el), 220);
                } else {
                    close(el);
                }
            }, 200);
        };

        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', (event) => {
            if (! swipe || event.pointerId !== swipe.id) {
                return;
            }
            if (swipe.moving) {
                springBack(swipe.el);
                swipe.near.forEach(({ el }) => springBack(el));
                stopDragging();
            }
            swipe = null;
        });
    })();
</script>
