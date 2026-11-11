// Crimes by type side by side, on Compare areas: the dot plot's year slider, its legend and its tip. The slider takes
// the rows to any year the areas all have (TypeComparison's rows, in #area-types-data): the dots and lines slide to
// their shares, the rows re-sort, most first, and the types under 5% everywhere fold away again; the chart of crime
// each year marks the year too (areas.js, the "area:year" event). Pointing at an area in the legend, or pressing it,
// picks out its dots and shares. Pointing at a dot, or tapping it, says whose it is and how many crimes it was.
(() => {
    const card = document.querySelector('[data-type-dots]');
    const source = document.getElementById('area-types-data');

    if (!card || !source) {
        return;
    }

    const { years, names } = JSON.parse(source.textContent);
    const list = card.querySelector('[data-dots]');
    const rows = [...list.querySelectorAll('.area-dots-row')];
    const more = card.querySelector('[data-dots-more]');
    const tip = card.querySelector('[data-dots-tip]');
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const count = new Intl.NumberFormat('en-MY');
    const percent = (share) => (share > 0 && share < 1 ? t('under 1%') : `${Math.round(share)}%`);
    let shown = years.length - 1;
    let open = false;

    // The types under 5% everywhere, folded away or shown, and the button saying how many.
    const fold = () => {
        const folded = rows.filter((row) => row.hasAttribute('data-folded') && !row.hasAttribute('data-absent'));
        for (const row of rows) {
            row.hidden = row.hasAttribute('data-absent') || (!open && row.hasAttribute('data-folded'));
        }
        more.hidden = folded.length === 0;
        more.textContent = open ? t('Show fewer') : t('Show :count more', { count: folded.length });
        more.setAttribute('aria-expanded', String(open));
    };

    more.addEventListener('click', () => {
        open = !open;
        fold();
    });

    const showYear = (index) => {
        const year = years[index];
        if (!year) {
            return;
        }
        shown = index;
        tip.hidden = true;
        card.querySelector('[data-dots-heading]').textContent = t('Crimes by type in :year', { year: year.year });

        const byType = new Map(year.rows.map((row) => [row.type, row]));
        for (const row of rows) {
            const figures = byType.get(row.dataset.type);
            row.toggleAttribute('data-absent', !figures);
            row.toggleAttribute('data-folded', !!figures?.folded);
            if (!figures) {
                continue;
            }
            const span = row.querySelector('.area-dots-span');
            span.style.setProperty('--from', Math.min(...figures.shares));
            span.style.setProperty('--to', Math.max(...figures.shares));
            row.querySelectorAll('.area-dot').forEach((dot, at) => {
                dot.style.setProperty('--share', figures.shares[at]);
                dot.style.setProperty('--ring', figures.rings[at]);
                dot.classList.toggle('is-ring', figures.rings[at] > 0);
            });
            row.querySelectorAll('.area-dots-value').forEach((value, at) => {
                value.querySelector('[data-value]').textContent = percent(figures.shares[at]);
                value.classList.toggle('is-out', figures.standOut === at);
            });
        }

        // Most first in that year, each row gliding to its new place.
        const order = year.rows.map((figures) => rows.find((row) => row.dataset.type === figures.type)).filter(Boolean);
        const rest = rows.filter((row) => !order.includes(row));
        const was = new Map(rows.map((row) => [row, row.getBoundingClientRect().top]));
        list.append(...order, ...rest);
        fold();
        if (!still) {
            for (const row of order) {
                const by = was.get(row) - row.getBoundingClientRect().top;
                if (by !== 0 && !row.hidden && was.get(row) !== 0) {
                    row.animate([{ transform: `translateY(${by}px)` }, { transform: 'none' }], { duration: 450, easing: 'cubic-bezier(.2, .8, .2, 1)' });
                }
            }
        }

        document.dispatchEvent(new CustomEvent('area:year', { detail: { index } }));
    };

    const pick = card.querySelector('[data-year-pick]');
    const slider = pick?.querySelector('input');
    if (pick && slider && years.length > 1) {
        pick.hidden = false;
        slider.addEventListener('input', () => {
            // Past the dots' first slide out, which waits for the card to rise in: from now on they move at once.
            card.classList.add('is-settled');
            showYear(Number(slider.value));
            pick.querySelector('[data-year-shown]').textContent = years[shown].year;
            slider.setAttribute('aria-valuetext', String(years[shown].year));
        });
    }

    // An area picked out: for as long as it's pointed at, or once pressed, until it's pressed again.
    let pressed = null;
    const focus = (at) => {
        if (at === null) {
            delete list.dataset.focus;
        } else {
            list.dataset.focus = at;
        }
    };
    for (const button of card.querySelectorAll('.area-legend-pick')) {
        const at = button.dataset.area;
        button.addEventListener('pointerenter', () => focus(at));
        button.addEventListener('pointerleave', () => focus(pressed));
        button.addEventListener('click', () => {
            pressed = pressed === at ? null : at;
            for (const other of card.querySelectorAll('.area-legend-pick')) {
                other.setAttribute('aria-pressed', String(other.dataset.area === pressed));
            }
            focus(pressed);
        });
    }

    // A dot's tip, above it: whose it is, its share and its count that year.
    const say = (dot) => {
        const row = dot.closest('.area-dots-row');
        const at = Number(dot.dataset.area);
        const figures = years[shown].rows.find((each) => each.type === row.dataset.type);
        if (!figures) {
            return;
        }
        const name = document.createElement('strong');
        name.textContent = names[at];
        const swatch = document.createElement('span');
        swatch.className = 'area-swatch area-swatch-dot';
        swatch.style.setProperty('--swatch', dot.style.getPropertyValue('--swatch'));
        const line = document.createElement('span');
        line.textContent = `${row.querySelector('.area-dots-label').textContent} · ${percent(figures.shares[at])} · ${tn(figures.counts[at], ':count crime', ':count crimes', { count: count.format(figures.counts[at]) })}`;
        tip.replaceChildren(swatch, name, line);
        tip.hidden = false;

        // Over the dot, as far in as the card lets it.
        const box = card.getBoundingClientRect();
        const spot = dot.getBoundingClientRect();
        const half = tip.offsetWidth / 2;
        tip.style.left = `${Math.min(Math.max(spot.left + spot.width / 2 - box.left, half + 8), box.width - half - 8)}px`;
        tip.style.top = `${spot.top - box.top - 8}px`;
    };
    list.addEventListener('pointerover', (event) => {
        const dot = event.target.closest('.area-dot');
        if (dot) {
            say(dot);
        }
    });
    list.addEventListener('pointerout', (event) => {
        if (event.target.closest('.area-dot') && event.pointerType === 'mouse') {
            tip.hidden = true;
        }
    });
    document.addEventListener('pointerdown', (event) => {
        if (!event.target.closest('.area-dot')) {
            tip.hidden = true;
        }
    });

    fold();
})();
