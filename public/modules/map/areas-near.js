// "Near you" on Check an area: from the browser's location, the three police districts nearest the visitor, each
// linked to its profile, and a link to see the three side by side (nearby.js). The location stays in the browser.
(() => {
    const card = document.querySelector('[data-near]');
    const nearby = window.CrimeifyNearby;

    if (!card || !nearby || !('geolocation' in navigator)) {
        return;
    }

    const pins = JSON.parse(card.querySelector('[data-district-pins]').textContent);
    const results = card.querySelector('[data-near-results]');
    const button = card.querySelector('[data-near-button]');

    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) {
            node.textContent = text;
        }
        if (className) {
            node.className = className;
        }
        return node;
    };

    const say = (text, className) => results.replaceChildren(element('p', text, className));

    const list = (turf, coords) => {
        const nearest = nearby.nearest(turf, pins, coords.latitude, coords.longitude);
        const districts = element('ol', undefined, 'near-list');

        for (const pin of nearest) {
            const item = element('li');
            const name = element('a', pin.name);
            name.href = pin.profile;
            item.append(name, ' ', element('span', `${nearby.distance(pin.km)} · ${pin.region}`, 'area-sub'));
            districts.append(item);
        }

        // All three side by side.
        const compare = new URL(card.dataset.compare, window.location.href);
        nearest.forEach((pin) => compare.searchParams.append('areas[]', pin.path));
        const together = element('a', t('Compare these three'), 'btn btn-sm');
        together.href = compare.href;

        results.replaceChildren(
            element('p', t('Nearest police districts'), 'near-heading'),
            districts,
            together,
            element('p', t("In a straight line to each district's pin, mostly its police headquarters. Near a district's edge, the nearest pin may be a neighbouring district's."), 'area-note'),
        );
    };

    button.addEventListener('click', () => {
        button.disabled = true;
        say(t('Finding where you are…'), 'area-sub');

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => nearby.load(card.dataset.turf)
                .then((turf) => list(turf, coords), () => say(t("Couldn't work out what's near you. Reload the page to try again."), 'near-problem'))
                .finally(() => { button.disabled = false; }),
            (error) => {
                say(nearby.problem(error), 'near-problem');
                button.disabled = false;
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
        );
    });

    card.hidden = false;
})();
