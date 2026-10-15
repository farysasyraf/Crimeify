// "Near you" on Check an area: from the browser's location, or a place or address found by name (PlaceSearch), the
// three police districts nearest it, each linked to its profile, and a link to see the three side by side
// (nearby.js). The visitor's location stays in the browser. Without a browser that can give one, it's for places only.
(() => {
    const card = document.querySelector('[data-near]');
    const nearby = window.CrimeifyNearby;

    if (!card || !nearby) {
        return;
    }

    const pins = JSON.parse(card.querySelector('[data-district-pins]').textContent);
    const results = card.querySelector('[data-near-results]');
    const button = card.querySelector('[data-near-button]');
    const placeForm = card.querySelector('[data-place-search]');
    const placeList = card.querySelector('[data-place-results]');

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

    // The three nearest a point, for where the visitor is or, with place, a place they found.
    const list = (turf, lat, lng, place = null) => {
        const nearest = nearby.nearest(turf, pins, lat, lng);
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
            element('p', place ? t('Nearest police districts to :place', { place: place.name }) : t('Nearest police districts'), 'near-heading'),
            districts,
            together,
            element('p', t("In a straight line to each district's pin, mostly its police headquarters. Near a district's edge, the nearest pin may be a neighbouring district's."), 'area-note'),
        );
    };

    const withTurf = (then) => nearby.load(card.dataset.turf).then(then, () => say(t("Couldn't work out what's near you. Reload the page to try again."), 'near-problem'));

    // Where the visitor is, where the browser can give it.
    if ('geolocation' in navigator) {
        button.addEventListener('click', () => {
            button.disabled = true;
            placeList.hidden = true;
            say(t('Finding where you are…'), 'area-sub');

            navigator.geolocation.getCurrentPosition(
                ({ coords }) => withTurf((turf) => list(turf, coords.latitude, coords.longitude)).finally(() => { button.disabled = false; }),
                (error) => {
                    say(nearby.problem(error), 'near-problem');
                    button.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
            );
        });
    } else {
        button.hidden = true;
        card.querySelector('[data-near-privacy]').hidden = true;
    }

    // A place or address: the places it may be, to choose from, then the districts nearest the one chosen.
    let request = null;
    placeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const text = placeForm.elements.q.value.trim();
        if (text.length < 3) {
            return;
        }

        request?.abort();
        request = new AbortController();
        const url = new URL(placeForm.dataset.places, window.location.href);
        url.searchParams.set('q', text);
        placeList.hidden = true;
        say(t('Searching places…'), 'area-sub');

        fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((response) => (response.ok ? response.json() : { places: [], error: true }))
            .then(({ places, error }) => {
                if (places.length === 0) {
                    say(error ? t("Places can't be searched just now. Try again in a moment.") : t('No places found for “:text”.', { text }), error ? 'near-problem' : 'area-sub');
                    return;
                }

                results.replaceChildren();
                placeList.replaceChildren(...places.map((place) => {
                    const choice = element('button');
                    choice.type = 'button';
                    choice.append(element('strong', place.name), element('span', place.about));
                    choice.addEventListener('click', () => {
                        placeList.hidden = true;
                        withTurf((turf) => list(turf, place.lat, place.lng, place));
                    });
                    const item = element('li');
                    item.append(choice);
                    return item;
                }));
                placeList.hidden = false;
            })
            .catch((problem) => {
                if (problem.name !== 'AbortError') {
                    say(t("Places can't be searched just now. Try again in a moment."), 'near-problem');
                }
            });
    });

    card.hidden = false;
})();
