// Lists that refresh in place, as DataTables' do: choosing a filter, how many rows a page shows, or another page
// fetches the page again and swaps in only the parts it changes, not the whole page. Each part is marked
// data-live-region="name", with the parameters of the address it shows in data-live-params, like
// "state length page"; a GET form or a link to this page inside it refreshes it. The address changes as it would, so
// Back, reloading and sharing it work as before. Before a part goes, a script can ask whether to let it (the event
// live:leave, with promises to wait for in detail.asks), as the Crime data page does for unsaved counts; after, the
// new part sends live:updated. Without JavaScript, or if the fetch fails, the page loads as usual.
(() => {
    if (!document.querySelector('[data-live-region]') || !window.fetch || !window.DOMParser) {
        return;
    }

    const paramsOf = (region) => region.dataset.liveParams.split(/\s+/).filter(Boolean);
    const regionOf = (element) => element.closest('[data-live-region]');

    // What the lists show now, which a refresh compares its address with.
    let shown = new URL(window.location.href);
    let request = null;

    // Says what a refresh shows, like "Figures: 1–10 of 25", to screen readers.
    const announcer = document.createElement('div');
    announcer.className = 'visually-hidden';
    announcer.setAttribute('aria-live', 'polite');
    document.body.append(announcer);

    // A form's choices as the region last showed them, to put back if leaving it is called off.
    const snapshots = new WeakMap();
    const snapshot = (root) => {
        for (const form of root.querySelectorAll('form[method="get" i]')) {
            snapshots.set(form, [...form.elements].map((field) => [field, field.value, field.checked]));
        }
    };
    const restore = (region) => {
        for (const form of region.querySelectorAll('form[method="get" i]')) {
            for (const [field, value, checked] of snapshots.get(form) ?? []) {
                field.value = value;
                field.checked = checked;
                // Select2 draws the choice again, without sending another change.
                if (field.tagName === 'SELECT' && window.jQuery?.fn.select2) {
                    window.jQuery(field).trigger('change.select2');
                }
            }
        }
    };

    // The address a form asks for: this page's, with the region's parameters as the form's visible fields have them,
    // from its first page. Its hidden fields keep other lists' choices when there's no JavaScript; here the address
    // has them already.
    const formTarget = (form, region) => {
        const url = new URL(shown);
        const params = paramsOf(region);
        params.forEach((name) => url.searchParams.delete(name));

        for (const field of form.elements) {
            const skip = !field.name || field.type === 'hidden' || field.disabled || !params.includes(field.name)
                || (['checkbox', 'radio'].includes(field.type) && !field.checked);
            if (!skip && field.value !== '') {
                url.searchParams.set(field.name, field.value);
            }
        }

        return url;
    };

    // The address a link asks for: this page's, with the region's parameters as the link has them.
    const linkTarget = (link, region) => {
        const url = new URL(shown);
        const from = new URL(link.href);

        for (const name of paramsOf(region)) {
            if (from.searchParams.has(name)) {
                url.searchParams.set(name, from.searchParams.get(name));
            } else {
                url.searchParams.delete(name);
            }
        }

        return url;
    };

    // The parameters that differ between two addresses.
    const changes = (from, to) => {
        const names = new Set([...from.searchParams.keys(), ...to.searchParams.keys()]);
        return [...names].filter((name) => from.searchParams.get(name) !== to.searchParams.get(name));
    };

    // Where focus was in a region, to put it back after: a field by its id (a Select2 box by its list's), or a page
    // number by its text.
    const focusKey = () => {
        const active = document.activeElement;
        if (!active || !regionOf(active)) {
            return null;
        }
        const field = active.closest('.select2-container')?.previousElementSibling ?? active;
        return field.id ? { id: field.id } : { text: active.textContent.trim(), region: regionOf(active).dataset.liveRegion };
    };
    const refocus = (key) => {
        let target = key?.id ? document.getElementById(key.id) : null;
        if (target?.tagName === 'SELECT' && target.nextElementSibling?.classList.contains('select2-container')) {
            target = target.nextElementSibling.querySelector('.select2-selection');
        }
        if (!target && key?.text) {
            const region = document.querySelector(`[data-live-region="${key.region}"]`);
            const pager = [...(region?.querySelectorAll('.pager a, .pager span.btn') ?? [])];
            target = pager.find((each) => each.textContent.trim() === key.text) ?? region?.querySelector('.pager-current');
        }
        target?.focus({ preventScroll: true });
    };

    const refresh = async (url, { push = true } = {}) => {
        const changed = changes(shown, url);
        const regions = [...document.querySelectorAll('[data-live-region]')]
            .filter((region) => paramsOf(region).some((name) => changed.includes(name)));

        if (regions.length === 0) {
            return;
        }

        // What the regions going would lose, like unsaved counts, is asked about first.
        const asks = [];
        for (const region of regions) {
            region.dispatchEvent(new CustomEvent('live:leave', { bubbles: true, detail: { asks } }));
        }
        if (!(await Promise.all(asks)).every(Boolean)) {
            regions.forEach(restore);
            if (!push) {
                // Back or Forward called off: the address goes back to what's shown.
                history.pushState({ live: true }, '', shown);
            }
            return;
        }

        request?.abort();
        request = new AbortController();
        regions.forEach((region) => region.setAttribute('aria-busy', 'true'));

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: request.signal });
            // A login that ran out leads to the login page: that loads as a page.
            if (!response.ok || response.redirected) {
                throw new Error(`HTTP ${response.status}`);
            }

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = regions.map((region) => page.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`));
            if (fresh.includes(null)) {
                throw new Error('The page came back without the list.');
            }

            const focus = focusKey();
            if (push) {
                history.pushState({ live: true }, '', url);
            }
            shown = new URL(url);

            regions.forEach((region, index) => {
                const replacement = document.importNode(fresh[index], true);
                region.replaceWith(replacement);
                window.enhanceSelects?.(replacement);
                snapshot(replacement);
                replacement.dispatchEvent(new CustomEvent('live:updated', { bubbles: true }));
            });

            refocus(focus);

            // The list's top in view, if paging from the bottom left it above.
            const first = document.querySelector(`[data-live-region="${regions[0].dataset.liveRegion}"]`);
            if (first.getBoundingClientRect().top < 0) {
                first.scrollIntoView({ block: 'start' });
            }

            announcer.textContent = [...document.querySelectorAll('[data-live-region]')]
                .filter((region) => regions.some((each) => each.dataset.liveRegion === region.dataset.liveRegion))
                .map((region) => {
                    const heading = region.querySelector('h2')?.textContent.trim();
                    const range = region.querySelector('.pager .muted')?.textContent.trim() ?? 'nothing to list';
                    return heading ? `${heading}: ${range}` : null;
                })
                .filter(Boolean)
                .join('. ');
        } catch (problem) {
            if (problem.name !== 'AbortError') {
                // As without JavaScript.
                window.location.assign(url);
            }
        } finally {
            regions.forEach((region) => region.isConnected && region.removeAttribute('aria-busy'));
        }
    };

    // A filter form: choosing in it sends it (the page's own script, like crime-data.js, does that), or Show does.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const region = regionOf(form);
        if (!region || form.method.toLowerCase() !== 'get' || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        refresh(formTarget(form, region));
    });

    // A page number, Previous, Next or Clear: a link to this page. Opening it in a new tab still does.
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        const region = link && regionOf(link);
        if (!region || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target) {
            return;
        }
        const to = new URL(link.href);
        if (to.origin !== shown.origin || to.pathname !== shown.pathname) {
            return;
        }
        event.preventDefault();
        refresh(linkTarget(link, region));
    });

    // Back and Forward between the addresses refreshes made.
    history.replaceState({ live: true }, '');
    window.addEventListener('popstate', () => refresh(new URL(window.location.href), { push: false }));

    snapshot(document);
})();
