// "Check an area" in motion, kept light: each card rises into view once, one after another, and the numbers in its
// tiles count up to their figure; a profile's headline comes back small under the page's bar once it has scrolled
// away; and a police district's name glides from the link chosen into its profile's heading, where the browser has
// View Transitions (areas.css). Only position and opacity move, and nothing does for anyone whose system asks for less
// motion. Without this script, areas.css shows the cards after a moment.
(() => {
    const root = document.documentElement;
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const watching = 'IntersectionObserver' in window;

    root.classList.add('area-motion');

    // As the page goes to a police district's profile, the link chosen is named for its heading there, so the
    // browser moves one into the other. Not on a profile, whose heading has the name already.
    addEventListener('pageswap', (event) => {
        const url = event.activation?.entry?.url;

        if (!event.viewTransition || !url || document.querySelector('.area-hero h1')) {
            return;
        }

        const to = new URL(url);
        const profile = /\/areas\/[^/]+\/[^/]+$/.test(to.pathname);
        // One box, so one picture of it: a name across two lines would stop the transition.
        const link = profile && [...document.querySelectorAll('.area-page a[href]')].find((each) => each.pathname === to.pathname && each.getClientRects().length === 1);

        if (link) {
            link.style.viewTransitionName = 'area-title';
            event.viewTransition.finished.finally(() => link.style.removeProperty('view-transition-name'));
        }
    });

    // The headline again, small, once it has gone under the page's bar.
    const hero = document.querySelector('[data-area-hero]');
    const dock = document.querySelector('[data-area-dock]');

    if (hero && dock && watching) {
        const bar = document.querySelector('.public-topbar');
        const below = bar ? Math.round(bar.getBoundingClientRect().height) : 0;

        dock.style.setProperty('--area-dock-top', `${below}px`);
        new IntersectionObserver(([entry]) => {
            const away = !entry.isIntersecting && entry.boundingClientRect.top < below;
            dock.classList.toggle('is-shown', away);
            dock.inert = !away;
        }, { rootMargin: `-${below}px 0px 0px 0px` }).observe(hero);
    }

    const cards = document.querySelectorAll('.area-page > .area-card');

    if (still || !watching) {
        cards.forEach((card) => card.classList.add('is-in'));
        return;
    }

    // A figure like 1,234, −12% or 0.1% counts up from nothing. The figure itself stays in the page, hidden, for
    // screen readers; the count is only seen, in the width the figure will take, so nothing beside it moves.
    const shape = /^([−+]?)(\d[\d,]*)(\.\d+)?(%?)$/;
    const countUp = (number, delay) => {
        const text = number.textContent.trim();
        const parts = text.match(shape);

        if (!parts) {
            return;
        }

        const [, sign, whole, fraction = '', unit] = parts;
        const target = Number(whole.replaceAll(',', '') + fraction);

        // Too small to be worth counting.
        if (target < 10) {
            return;
        }

        const decimals = Math.max(0, fraction.length - 1);
        const format = new Intl.NumberFormat('en-MY', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        const shown = document.createElement('span');
        const kept = document.createElement('span');

        shown.className = 'area-count';
        shown.setAttribute('aria-hidden', 'true');
        shown.textContent = text;
        kept.className = 'visually-hidden';
        kept.textContent = text;
        number.replaceChildren(shown, kept);
        shown.style.minWidth = `${shown.getBoundingClientRect().width}px`;
        shown.textContent = `${sign}${format.format(0)}${unit}`;

        const start = performance.now() + delay;
        const frame = (now) => {
            const progress = Math.min(1, Math.max(0, (now - start) / 900));
            shown.textContent = progress === 1 ? text : `${sign}${format.format(target * (1 - (1 - progress) ** 3))}${unit}`;

            if (progress < 1) {
                requestAnimationFrame(frame);
            }
        };
        requestAnimationFrame(frame);
    };

    // Each card once it's in view, those that come into view together one after another.
    const seen = new IntersectionObserver((entries) => {
        entries.filter((entry) => entry.isIntersecting).forEach((entry, at) => {
            const delay = at * 80;

            entry.target.style.setProperty('--area-delay', `${delay}ms`);
            entry.target.classList.add('is-in');
            entry.target.querySelectorAll('.area-stat strong, .area-hero-number strong').forEach((number) => countUp(number, delay + 150));
            seen.unobserve(entry.target);
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    cards.forEach((card) => seen.observe(card));
})();
