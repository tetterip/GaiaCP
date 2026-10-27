{{-- Rings the top bar's bell when its unread count goes up: the first
     page of a visit with unread notifications, then each time polling brings more. The last count seen is kept for
     the browser tab's session, so moving between pages does not ring it again. --}}
<script>
    (() => {
        const key = 'gaiacp-bell-seen';
        const check = () => {
            const button = document.querySelector('.fi-topbar-database-notifications-btn');
            if (! button) {
                return;
            }

            const count = parseInt(button.querySelector('.fi-badge')?.textContent ?? '0', 10) || 0;
            let seen = 0;
            try { seen = parseInt(sessionStorage.getItem(key) ?? '0', 10) || 0; } catch (e) {}

            // An animation, not a class: Livewire's next morph of the bell would take a class away mid-ring
            if (count > seen && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                const icon = button.querySelector('.fi-icon');
                icon.style.transformOrigin = '50% 10%';
                icon.animate([0, 14, -14, 14, -14, 14, -14, 6, -4, 0].map((deg) => ({ transform: `rotate(${deg}deg)` })), { duration: 1100, easing: 'ease-in-out' });
            }

            if (count !== seen) {
                try { sessionStorage.setItem(key, String(count)); } catch (e) {}
            }
        };

        // The bell is a Livewire component that renders after the page and again on each poll
        let queued = null;
        new MutationObserver(() => {
            clearTimeout(queued);
            queued = setTimeout(check, 100);
        }).observe(document.body, { childList: true, subtree: true, characterData: true });
        check();
    })();
</script>
