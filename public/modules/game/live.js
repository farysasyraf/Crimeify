// A live game on a phone (LivePlayController, LiveGames), of Higher or Lower or DistrictGuessr: joins with the code on
// the big screen and a name, then checks where the game is up to about every second and shows it: waiting for the host,
// a few seconds to get ready, the round until its time is up (two cards with Higher and Lower, or a district to pin on
// the map, guessr-map.js, and Confirm), then its answer with the player's points and place, and at the end the top 10.
// Each round opens on the server's clock, worked out from each check, so every phone shows it at the same moment. The
// game and the player are remembered in the tab, so reloading keeps them in it.
(() => {
    const live = document.querySelector('[data-live]');

    if (!live) {
        return;
    }

    const form = live.querySelector('[data-live-join]');
    const template = live.querySelector('template[data-game-card]');
    const part = (name) => live.querySelector(`[data-live-part="${name}"]`);
    const slot = (name) => live.querySelector(`[data-live="${name}"]`);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const keys = { game: 'crimeify.live', name: 'crimeify.game.name' };

    // The player ({ game, player, token, name }), the game as last checked, and the server's clock less this one's.
    let me = null;
    let view = null;
    let offset = 0;
    let checking = null;
    // The round showing, the one whose answer is, this player's answer ({ round, guess }, the guess 'pin' in
    // DistrictGuessr), and an answer on its way.
    let dealt = null;
    let revealed = null;
    let guessed = null;
    let sending = false;
    // DistrictGuessr's map: the states' outlines, fetched once the game's known, the map once drawn ({ map, malaysia }),
    // the player's pin and the answer's marks, and the round's map once it's set out, for the answer to wait for.
    let outlines = null;
    let drawing = null;
    let pin = null;
    let marks = [];
    let placed = Promise.resolve();

    const serverNow = () => Date.now() + offset;
    const answered = () => guessed !== null && view !== null && guessed.round === view.round;
    const pins = () => view?.game === 'districtguessr';

    const keep = (value) => {
        try {
            if (value) {
                sessionStorage.setItem(keys.game, JSON.stringify(value));
            } else {
                sessionStorage.removeItem(keys.game);
            }
        } catch {
            // Not kept: reloading asks to join again.
        }
    };

    const kept = () => {
        try {
            return JSON.parse(sessionStorage.getItem(keys.game) ?? 'null');
        } catch {
            return null;
        }
    };

    // Ask the game, as this player once joined. A problem comes back as an Error with what to say, and its status.
    const request = async (path, body) => {
        let response;

        try {
            response = await fetch(`${live.dataset.api}${path}${live.dataset.language}`, {
                method: body ? 'POST' : 'GET',
                headers: {
                    Accept: 'application/json',
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                    ...(me ? { 'X-Player': String(me.player), 'X-Player-Token': me.token } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
                cache: 'no-store',
            });
        } catch {
            throw Object.assign(new Error(t("Couldn't reach the game. Check your connection and try again.")), { status: 0 });
        }

        const data = await response.json().catch(() => ({}));

        if (response.ok) {
            return data;
        }

        const said = response.status < 500 ? (data.errors ? Object.values(data.errors)[0]?.[0] : data.message) : null;
        const known = { 429: t('Too many tries for now. Wait a minute, then play again.') };

        throw Object.assign(new Error(known[response.status] ?? said ?? t('Something went wrong. Try again.')), { status: response.status });
    };

    const show = (name) => {
        for (const section of live.querySelectorAll('[data-live-part]')) {
            section.hidden = section.dataset.livePart !== name;
        }
    };

    // What's only for one game: its title, how to play it, and its round. Before joining, the page's own title.
    const showGame = (game) => {
        for (const element of document.querySelectorAll('[data-live-game]')) {
            element.hidden = element.dataset.liveGame !== (game ?? 'none');
        }
    };

    const say = (name, text, kind = '') => {
        const element = slot(name);
        element.textContent = text;
        element.hidden = text === '';
        element.className = element.className.replace(/ ?is-(right|near|wrong)/g, '') + (kind ? ` is-${kind}` : '');
    };

    const joinError = (message) => {
        const error = live.querySelector('[data-live-error]');
        error.textContent = message;
        error.hidden = !message;
    };

    // Out of the game, as when it's gone: back to joining, saying why.
    const leave = (message = '') => {
        clearTimeout(checking);
        me = view = dealt = revealed = guessed = null;
        keep(null);
        showGame(null);
        show('join');
        joinError(message);
    };

    const check = async () => {
        clearTimeout(checking);

        if (!me) {
            return;
        }

        const sent = Date.now();

        try {
            const state = await request(`/${me.game}`);
            offset = state.now - (sent + Date.now()) / 2;
            render(state);
        } catch (problem) {
            if (problem.status === 403 || problem.status === 404) {
                leave(problem.message);
                return;
            }
            // A dropped connection or a busy server: the next check tries again.
        }

        if (me && view?.phase !== 'finished') {
            checking = setTimeout(check, 1000);
        }
    };

    const render = (state) => {
        view = state;
        showGame(state.game);

        if (pins()) {
            outlines ??= GuessrMap.load(live.dataset.boundaries);
        }

        if (state.guess) {
            // As after reloading the page mid-round.
            guessed = { round: state.round, guess: state.guess };
        }

        if (state.phase === 'lobby') {
            show('lobby');
            slot('welcome').textContent = t("You're in, :name!", { name: state.name });
            slot('players').textContent = tn(state.players, ':count player in the game', ':count players in the game');
        } else if (state.phase === 'reveal') {
            reveal(state);
        } else if (state.phase === 'finished') {
            finish(state);
        }

        // Getting ready and answering follow the clock.
        tick();
    };

    // A card from the template: the one shown, with its count, or the one to guess, with Higher and Lower.
    const card = (face, kind) => {
        const element = template.content.firstElementChild.cloneNode(true);
        const field = (name) => element.querySelector(`[data-card="${name}"]`);

        field('icon').textContent = face.icon;
        field('type').textContent = face.type;
        field('district').textContent = face.district;
        field('place').textContent = face.place;
        field('year').textContent = face.year;
        element.classList.add(`game-card-${kind}`);

        if (kind === 'shown') {
            field('count').hidden = false;
            field('crimes').textContent = number.format(face.crimes);
        } else {
            field('guess').hidden = false;
            field('ask').textContent = t('Higher or lower than :count?', { count: number.format(view.cards[0].crimes) });
            element.querySelectorAll('[data-guess]').forEach((button) => {
                button.addEventListener('click', () => answer(button.dataset.guess));
            });
        }

        return element;
    };

    // Higher or Lower's two cards.
    const dealCards = () => {
        const versus = document.createElement('span');
        versus.className = 'game-vs';
        versus.setAttribute('aria-hidden', 'true');
        versus.textContent = 'VS';

        const first = card(view.cards[0], 'shown');
        const second = card(view.cards[1], 'next');

        if (!calm) {
            first.classList.add('is-entering');
            second.classList.add('is-entering');
        }

        slot('cards').replaceChildren(first, versus, second);
    };

    // A pin where the player tapped, or the pin moved there, while the round takes answers.
    const drop = (map, latlng) => {
        const now = serverNow();

        if (!pins() || answered() || sending || revealed === view.round || now < view.opensAt - 500 || now > view.closesAt) {
            return;
        }

        if (pin) {
            pin.setLatLng(latlng);
        } else {
            pin = L.marker(latlng, { icon: GuessrMap.pin('guess'), draggable: true, keyboard: false }).addTo(map);
        }

        slot('confirm').disabled = false;
    };

    // DistrictGuessr's district, and the map, cleared and framed, with the pin already confirmed, as after reloading.
    const setOut = () => {
        const round = view.round;
        const mine = view.pin;

        slot('district').textContent = view.district.name;
        slot('region').textContent = view.district.place;
        slot('foot').hidden = false;
        slot('confirm').disabled = true;

        drawing ??= GuessrMap.draw(slot('map'), outlines ?? GuessrMap.load(live.dataset.boundaries)).then((drawn) => {
            drawn.map.on('click', (event) => drop(drawn.map, event.latlng));
            return drawn;
        });

        placed = drawing.then(({ map, malaysia }) => {
            if (dealt !== round) {
                return;
            }

            [pin, ...marks].forEach((layer) => layer?.remove());
            pin = mine ? L.marker([mine.lat, mine.lng], { icon: GuessrMap.pin('guess'), keyboard: false }).addTo(map) : null;
            marks = [];
            map.invalidateSize();
            map.fitBounds(malaysia, { paddingTopLeft: [12, 12], paddingBottomRight: [12, 90] });
        });

        // The whole map in view, with Confirm, as on a phone, where the title takes the top of the screen.
        if (slot('map').getBoundingClientRect().bottom > window.innerHeight) {
            part('round').scrollIntoView({ block: 'start', behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // The answer as it shows while waiting for the others.
    const lockedIn = (guess) => (guess === 'pin'
        ? t('Pinned. Waiting for the others…')
        : t('Locked in: :guess. Waiting for the others…', { guess: guess === 'higher' ? t('Higher') : t('Lower') }));

    // The round, once each round.
    const deal = () => {
        show('round');

        if (dealt === view.round) {
            return;
        }

        dealt = view.round;
        slot('round').textContent = t('Round :round of :rounds', { round: view.round, rounds: view.rounds });
        say('standing', '');

        if (pins()) {
            setOut();
        } else {
            dealCards();
        }

        if (answered()) {
            lock(guessed.guess);
            say('note', lockedIn(guessed.guess));
        } else {
            say('note', '');
            slot('cards').querySelector('[data-guess="higher"]')?.focus({ preventScroll: true });
        }
    };

    // No more answering: the buttons, or the pin and Confirm, which goes.
    const lock = (guess = null) => {
        if (pins()) {
            pin?.dragging?.disable();
            slot('confirm').disabled = true;
            slot('foot').hidden = true;
            return;
        }

        slot('cards').querySelectorAll('[data-guess]').forEach((button) => {
            button.disabled = true;
            button.classList.toggle('is-chosen', button.dataset.guess === guess);
        });
    };

    const unlock = () => {
        if (pins()) {
            pin?.dragging?.enable();
            slot('confirm').disabled = pin === null;
            slot('foot').hidden = false;
            return;
        }

        slot('cards').querySelectorAll('[data-guess]').forEach((button) => {
            button.disabled = false;
            button.classList.remove('is-chosen');
        });
    };

    // The time left to answer, to a tenth of a second, and getting ready before it. When it's up, a pin dropped but not
    // confirmed counts as it is.
    const tick = () => {
        if (!view || !['ready', 'question'].includes(view.phase)) {
            return;
        }

        const now = serverNow();

        if (now < view.opensAt) {
            show('ready');
            slot('ready-round').textContent = t('Round :round of :rounds', { round: view.round, rounds: view.rounds });
            slot('countdown').textContent = Math.ceil((view.opensAt - now) / 1000);
            return;
        }

        deal();

        const left = Math.max(0, view.closesAt - now);
        slot('seconds').textContent = Math.ceil(left / 1000);
        slot('bar').style.transform = `scaleX(${left / (view.seconds * 1000)})`;
        live.classList.toggle('is-hurrying', left > 0 && left <= 3000);

        if (left > 0 || answered() || sending) {
            return;
        }

        if (pins() && pin && now <= view.closesAt + 1000) {
            answer('pin', true);
        } else {
            lock();
            say('note', t("Time's up. The answer's coming…"));
        }
    };

    // The answer: higher or lower, or the pin, as it is when the time's up.
    const answer = async (guess, late = false) => {
        const now = serverNow();
        const at = guess === 'pin' ? pin?.getLatLng() : null;

        if (sending || answered() || !view || revealed === view.round || (guess === 'pin' && !at)
            || now < view.opensAt - 500 || now > view.closesAt + (late ? 1000 : 0)) {
            return;
        }

        sending = true;
        guessed = { round: view.round, guess };
        lock(guess);
        say('note', lockedIn(guess));

        try {
            await request(`/${me.game}/answer`, { round: view.round, ...(at ? { lat: at.lat, lng: at.lng } : { guess }) });
        } catch (problem) {
            // Not taken, unless the round has stopped taking answers or already has this one.
            if (problem.status !== 409) {
                guessed = null;
                unlock();
            }

            say('note', problem.message, 'wrong');
        } finally {
            sending = false;
        }
    };

    // The count counts up to the figure, unless motion is turned down.
    const countUp = (element, to) => {
        if (calm) {
            element.textContent = number.format(to);
            return;
        }

        const start = performance.now();
        const step = (now) => {
            const done = Math.min(1, (now - start) / 600);
            element.textContent = number.format(Math.round(to * (1 - (1 - done) ** 3)));

            if (done < 1) {
                requestAnimationFrame(step);
            }
        };

        requestAnimationFrame(step);
    };

    // Higher or Lower's answer: the count that was hidden, and whether the player was right.
    const showCount = (state) => {
        const next = slot('cards').querySelector('.game-card-next');
        next.querySelector('[data-card="guess"]').hidden = true;
        next.querySelector('[data-card="count"]').hidden = false;
        countUp(next.querySelector('[data-card="crimes"]'), state.answer);

        const result = state.result;
        const was = number.format(state.answer);
        next.classList.add(result?.correct ? 'is-right' : 'is-wrong');

        if (result === null) {
            say('note', t('No answer this round. It was :answer.', { answer: was }), 'wrong');
        } else if (result.correct) {
            say('note', t('Right! +:points points', { points: number.format(result.points) }), 'right');
        } else {
            say('note', t('Wrong: it was :answer.', { answer: was }), 'wrong');
        }
    };

    // DistrictGuessr's answer: where the district is, with a dashed line from the player's pin, how far off it was and
    // its points.
    const showPlace = (state) => {
        const result = state.result;
        slot('foot').hidden = true;

        if (result === null) {
            say('note', t("Time's up, with no pin: it's here."), 'wrong');
        } else {
            const said = tn(result.points, ':km km away · :points point', ':km km away · :points points', { km: GuessrMap.km(result.km), points: number.format(result.points) });
            say('note', said, result.points >= 500 ? 'right' : 'near');
        }

        placed = placed.then(() => drawing).then(({ map }) => {
            const answer = L.latLng(state.answer.lat, state.answer.lng);
            const at = state.pin ? L.latLng(state.pin.lat, state.pin.lng) : null;

            // Only the pin that counted.
            if (!at) {
                pin?.remove();
                pin = null;
            }

            marks.push(L.marker(answer, { icon: GuessrMap.pin('answer'), keyboard: false, interactive: false }).addTo(map));

            if (at) {
                marks.push(GuessrMap.line(at, answer).addTo(map));
                map.fitBounds(L.latLngBounds([at, answer]), { padding: [50, 50], maxZoom: 9 });
            } else {
                map.setView(answer, 7);
            }
        });
    };

    // The round's answer, once, and how this player did, with their points and place.
    const reveal = (state) => {
        deal();
        say('standing', t('Your points: :score · Place :rank of :count', { score: number.format(state.score), rank: state.rank, count: state.players }));

        if (revealed === state.round) {
            return;
        }

        revealed = state.round;
        live.classList.remove('is-hurrying');
        slot('seconds').textContent = 0;
        slot('bar').style.transform = 'scaleX(0)';
        lock(answered() ? guessed.guess : null);

        if (pins()) {
            showPlace(state);
        } else {
            showCount(state);
        }
    };

    // The end: this player's place and points, and the top 10, with this player below them if they're not in it.
    const finish = (state) => {
        clearTimeout(checking);
        keep(null);

        if (!part('finished').hidden) {
            return;
        }

        show('finished');
        slot('place').textContent = t('Place :rank of :count', { rank: state.rank, count: state.players });
        slot('final').textContent = number.format(state.score);

        const rows = [...state.leaderboard];
        if (!rows.some((row) => row.you)) {
            rows.push(null, { rank: state.rank, name: state.name, score: state.score, you: true });
        }

        slot('board').replaceChildren(...rows.map((row) => {
            const item = document.createElement('li');

            if (row === null) {
                item.className = 'live-board-gap';
                item.textContent = '…';
                return item;
            }

            item.className = `live-board-row${row.you ? ' is-you' : ''}${row.rank <= 3 ? ` is-top-${row.rank}` : ''}`;
            for (const [kind, text] of [['rank', row.rank], ['name', row.name], ['score', number.format(row.score)]]) {
                const cell = document.createElement('span');
                cell.className = `live-board-${kind}`;
                cell.textContent = text;
                item.append(cell);
            }

            return item;
        }));

        document.getElementById('live-end-heading').focus({ preventScroll: true });
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        joinError('');
        me = null;

        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;

        try {
            const joined = await request('/join', {
                code: form.elements.code.value.replace(/\D/g, ''),
                name: form.elements.name.value.trim(),
            });
            me = { game: joined.game, player: joined.player, token: joined.token, name: joined.name };
            keep(me);

            try {
                localStorage.setItem(keys.name, joined.name);
            } catch {
                // The name is typed again next time.
            }

            check();
        } catch (problem) {
            joinError(problem.message);
        } finally {
            button.disabled = false;
        }
    });

    slot('confirm').addEventListener('click', () => answer('pin'));

    // ↑ for higher and ↓ for lower, while the round's cards wait for an answer; Enter confirms the pin.
    document.addEventListener('keydown', (event) => {
        if (part('round').hidden || answered() || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const guess = pins()
            ? (event.key === 'Enter' && !slot('confirm').disabled && !event.target.closest?.('a, button, input') ? 'pin' : null)
            : { ArrowUp: 'higher', ArrowDown: 'lower' }[event.key];

        if (!guess) {
            return;
        }

        event.preventDefault();
        answer(guess);
    });

    setInterval(tick, 100);

    try {
        form.elements.name.value = localStorage.getItem(keys.name) ?? '';
    } catch {
        // Typed each time, then.
    }

    me = kept();

    if (me) {
        check();
    } else {
        show('join');
        (form.elements.code.value ? form.elements.name : form.elements.code).focus({ preventScroll: true });
    }
})();
