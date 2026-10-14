// DistrictGuessr (DistrictGuessrController): starts a game with the name given, then after a short countdown asks for a
// police district on a map of Malaysia with only its states' outlines (guessr-map.js). A tap drops a pin, which can be dragged or tapped elsewhere, and Confirm sends it; the server says how
// far off it was, its points, and where the district is, shown with a dashed line from the pin. Next district asks for
// the next; after the last, every district's points. When the time runs out, the pin counts as it is, or none. The
// name is remembered in the browser, as in Higher or Lower.
(() => {
    const game = document.querySelector('[data-guessr]');

    if (!game || typeof L === 'undefined' || !window.GuessrMap) {
        return;
    }

    const part = (name) => game.querySelector(`[data-guessr-part="${name}"]`);
    const slot = (name) => game.querySelector(`[data-guessr="${name}"]`);
    const button = (name) => game.querySelector(`[data-guessr-action="${name}"]`);
    const form = game.querySelector('[data-guessr-start]');
    const seconds = Number(game.dataset.seconds);
    const rounds = Number(game.dataset.rounds);
    const points = Number(game.dataset.points);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nameKey = 'crimeify.game.name';

    // The states' outlines, fetched as the page loads, so the map is ready when the game starts.
    const boundaries = GuessrMap.load(game.dataset.boundaries);

    // The game, the district being guessed (none between its guess and the next), the map and what's on it, the clock,
    // and a request on its way.
    let token = null;
    let district = null;
    let score = 0;
    let summary = null;
    let map = null;
    let malaysia = null;
    let pin = null;
    let marks = [];
    let clock = null;
    let busy = false;

    try {
        form.elements.name.value = localStorage.getItem(nameKey) ?? '';
    } catch {
        // Without the browser's storage, the name is typed each time.
    }

    // Send to the game, as JSON. A problem comes back as an Error with what to tell the player.
    const post = async (url, body) => {
        let response;

        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
                body: JSON.stringify(body),
            });
        } catch {
            throw new Error(t("Couldn't reach the game. Check your connection and try again."));
        }

        const data = await response.json().catch(() => ({}));

        if (response.ok) {
            return data;
        }

        const said = response.status < 500 ? (data.errors ? Object.values(data.errors)[0]?.[0] : data.message) : null;
        const known = {
            419: t('This page has expired. Reload it to play.'),
            429: t('Too many tries for now. Wait a minute, then play again.'),
        };

        throw new Error(known[response.status] ?? said ?? t('Something went wrong. Try again.'));
    };

    const show = (name) => {
        for (const section of game.querySelectorAll('[data-guessr-part]')) {
            section.hidden = section.dataset.guessrPart !== name;
        }
    };

    // The map, once.
    const setUp = async () => {
        if (map) {
            map.invalidateSize();
            return;
        }

        ({ map, malaysia } = await GuessrMap.draw(slot('map'), boundaries));
        map.on('click', (event) => drop(event.latlng));
    };

    // Malaysia, clear of the bar over the map's foot.
    const frame = () => map.fitBounds(malaysia, { paddingTopLeft: [12, 12], paddingBottomRight: [12, 90] });

    // A pin where the player tapped, or the pin moved there, while a district waits for its guess.
    const drop = (latlng) => {
        if (!district || busy) {
            return;
        }

        if (pin) {
            pin.setLatLng(latlng);
        } else {
            pin = L.marker(latlng, { icon: GuessrMap.pin('guess'), draggable: true, keyboard: false }).addTo(map);
        }

        button('confirm').disabled = false;
    };

    const clear = () => {
        [pin, ...marks].forEach((layer) => layer?.remove());
        pin = null;
        marks = [];
    };

    // The time left: a bar that empties and the seconds, amber for the last few. At none, the pin counts as it is.
    const startClock = () => {
        const bar = slot('bar');
        const end = performance.now() + seconds * 1000;

        bar.style.transition = 'none';
        bar.style.transform = 'scaleX(1)';
        bar.getBoundingClientRect();
        bar.style.transition = `transform ${seconds}s linear`;
        bar.style.transform = 'scaleX(0)';

        const tick = () => {
            const left = Math.max(0, Math.ceil((end - performance.now()) / 1000));
            slot('seconds').textContent = left;
            game.classList.toggle('is-hurrying', left <= 5);

            if (left === 0) {
                guess();
            }
        };

        tick();
        clock = setInterval(tick, 200);
    };

    const stopClock = () => {
        clearInterval(clock);
        const bar = slot('bar');
        bar.style.transform = getComputedStyle(bar).transform;
        bar.style.transition = 'none';
        game.classList.remove('is-hurrying');
    };

    const ask = (asked) => {
        district = asked;
        clear();
        frame();
        slot('round').textContent = t('Round :round of :rounds', { round: asked.round, rounds });
        slot('district').textContent = asked.name;
        slot('place').textContent = asked.place;
        slot('result').textContent = '';
        slot('result').className = 'guessr-result';
        button('confirm').hidden = false;
        button('confirm').disabled = true;
        button('next').hidden = true;

        // The whole map in view, with its buttons, as on a phone, where the title takes the top of the screen.
        if (slot('map').parentElement.getBoundingClientRect().bottom > window.innerHeight) {
            part('round').scrollIntoView({ block: 'start', behavior: calm ? 'auto' : 'smooth' });
        }

        startClock();
    };

    // The seconds before the first district, counting down. Resolves when they're up.
    const countdown = (wait) => new Promise((resolve) => {
        const end = Date.now() + wait * 1000;
        show('ready');

        const step = () => {
            const left = Math.ceil((end - Date.now()) / 1000);

            if (left <= 0) {
                resolve();
                return;
            }

            slot('countdown').textContent = left;
            setTimeout(step, 100);
        };

        step();
    });

    const start = async () => {
        if (busy) {
            return;
        }

        const name = form.elements.name.value.trim();
        const error = game.querySelector('[data-guessr-error]');
        error.hidden = true;
        busy = true;

        try {
            const started = await post(game.dataset.start, { name });

            try {
                localStorage.setItem(nameKey, name);
            } catch {
                // Not remembered, then.
            }

            token = started.token;
            score = 0;
            summary = null;
            slot('score').textContent = 0;
            await countdown(started.ready);
            show('round');
            await setUp();
            busy = false;
            ask(started.district);
        } catch (problem) {
            busy = false;
            show('intro');
            error.textContent = problem.message;
            error.hidden = false;
            form.elements.name.focus();
        }
    };

    // The pin as it is, or none, for the district: how far off, its points, and where the district is.
    const guess = async () => {
        if (!district || busy) {
            return;
        }

        busy = true;
        stopClock();
        button('confirm').disabled = true;
        pin?.dragging?.disable();
        const at = pin?.getLatLng();

        let result;

        try {
            result = await post(game.dataset.guess, { token, lat: at?.lat ?? null, lng: at?.lng ?? null });
        } catch (problem) {
            busy = false;
            slot('result').textContent = problem.message;
            slot('result').className = 'guessr-result is-wrong';
            return;
        }

        district = null;
        busy = false;
        score = result.score;
        slot('score').textContent = number.format(score);

        const answer = L.latLng(result.answer.lat, result.answer.lng);
        marks.push(L.marker(answer, { icon: GuessrMap.pin('answer'), keyboard: false, interactive: false }).addTo(map));

        if (at && result.km !== null) {
            marks.push(GuessrMap.line(at, answer).addTo(map));
            map.fitBounds(L.latLngBounds([at, answer]), { paddingTopLeft: [60, 60], paddingBottomRight: [60, 120], maxZoom: 9 });
            const km = GuessrMap.km(result.km);
            slot('result').textContent = tn(result.points, ':km km away · :points point', ':km km away · :points points', { km, points: number.format(result.points) });
            slot('result').className = `guessr-result ${result.points >= 500 ? 'is-right' : 'is-near'}`;
        } else {
            map.setView(answer, 8);
            slot('result').textContent = t("Time's up, with no pin: it's here.");
            slot('result').className = 'guessr-result is-wrong';
        }

        if (result.over) {
            summary = result.rounds;
        }

        slot('next-label').textContent = result.over ? t('See your score') : t('Next district');
        button('confirm').hidden = true;
        button('next').hidden = false;
        button('next').focus({ preventScroll: true });
    };

    const next = async () => {
        if (busy) {
            return;
        }

        if (summary) {
            over();
            return;
        }

        busy = true;
        button('next').disabled = true;

        try {
            const asked = await post(game.dataset.next, { token });
            busy = false;
            ask(asked.district);
        } catch (problem) {
            busy = false;
            slot('result').textContent = problem.message;
            slot('result').className = 'guessr-result is-wrong';
        } finally {
            button('next').disabled = false;
        }
    };

    // The end: the score out of the most there is, and every district's.
    const over = () => {
        show('over');
        slot('final').textContent = number.format(score);
        slot('out-of').textContent = t('out of :most', { most: number.format(rounds * points) });
        slot('rounds').replaceChildren(...summary.map((row) => {
            const item = document.createElement('li');
            const km = row.km === null ? t('No pin') : `${GuessrMap.km(row.km)} km`;

            for (const [kind, text] of [['name', row.name], ['place', row.place], ['km', km], ['points', number.format(row.points)]]) {
                const cell = document.createElement('span');
                cell.className = `guessr-round-${kind}`;
                cell.textContent = text;
                item.append(cell);
            }

            return item;
        }));
        document.getElementById('guessr-over-heading').focus({ preventScroll: true });
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    button('confirm').addEventListener('click', guess);
    button('next').addEventListener('click', next);
    button('again').addEventListener('click', () => (form.elements.name.value.trim() === '' ? show('intro') : start()));
    button('rename').addEventListener('click', () => {
        show('intro');
        form.elements.name.focus();
    });

    // Enter confirms the pin, or goes on to the next district.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || part('round').hidden || event.target.closest?.('a, button, input')) {
            return;
        }

        if (!button('confirm').hidden && !button('confirm').disabled) {
            event.preventDefault();
            guess();
        } else if (!button('next').hidden) {
            event.preventDefault();
            next();
        }
    });
})();
