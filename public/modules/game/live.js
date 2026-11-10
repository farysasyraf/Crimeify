// A live game on a phone (LivePlayController, LiveGames), of Higher or Lower, DistrictGuessr, Skyline, Case File or Line-Up:
// joins with the code on the big screen and a name, then checks where the game is up to about every second and shows
// it: waiting for the host, a few seconds to get ready, the round until its time is up (two cards with Higher and Lower,
// a district to pin on the map, guessr-map.js, and Confirm, a state's towers, skyline-round.js, with the slider and
// Confirm, or a case in its pixel screen, case-screen.js, its clues opening as the game says, and the suspects to
// accuse, what's said said in its text box, or a line-up, line-up-room.js, to put in order and Confirm), then
// its answer with the player's points and place, and at the end the top 10.
// Each round opens on the server's clock, worked out from each check, so every phone shows it at the same moment. The
// game and the player are remembered in the tab, so reloading keeps them in it. While the game's on, the screen stays
// on, and on phones that can, a buzz says a round has opened and its answer is in (awake.js).
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
    // Skyline's round (skyline-round.js), once made, and the round's towers once they're set out, for what comes after
    // to wait for.
    let sky = null;
    let shown = Promise.resolve();
    // Case File's screen, once made.
    let caseScreen = null;
    // Line-Up's room, once made.
    let lineUpRoom = null;

    const serverNow = () => Date.now() + offset;
    const answered = () => guessed !== null && view !== null && guessed.round === view.round;
    const pins = () => view?.game === 'districtguessr';
    const skyline = () => view?.game === 'skyline';
    const caseFile = () => view?.game === 'case-file';
    const lineUp = () => view?.game === 'line-up';
    // Line-Up's controls along the foot of its room: Confirm, and what's said.
    const luControl = (name) => slot('lineup').querySelector(`[data-lu="${name}"]`);
    // Higher or Lower's Last One Standing, and whether this player sits out this round: out before it, or watching.
    const standingMode = () => view?.mode === 'last-one-standing';
    const sittingOut = () => standingMode() && view.out !== null && view.out !== undefined && view.out !== view.round;

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
        // Case File's, in its screen's text box.
        if (caseFile() && name === 'note' && text) {
            caseScreen?.say(text);
        }

        // Line-Up's, along the foot of its room, in place of which way the order goes and how to move them.
        if (lineUp() && name === 'note') {
            luControl('result').textContent = text;
            luControl('result').hidden = text === '';
            luControl('result').className = `lu-result${kind ? ` is-${kind}` : ''}`;
            luControl('scale').hidden = text !== '';
            luControl('help').hidden = text !== '';
        }

        // And the player's points and place, under it.
        if (lineUp() && name === 'standing') {
            luControl('standing').textContent = text;
            luControl('standing').hidden = text === '';
        }

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
        window.CrimeifyAwake?.keep(false);
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
        window.CrimeifyAwake?.keep(state.phase !== 'finished');

        if (pins()) {
            outlines ??= GuessrMap.load(live.dataset.boundaries);
            slot('level-rule').textContent = state.difficulty === 'easy'
                ? t("This game is on Easy: the map zooms into each district's state.")
                : t('This game is on Hard: the whole of Malaysia.');
        } else if (skyline()) {
            outlines ??= GuessrMap.load(live.dataset.boundaries);
            SkylineRound.load();
        } else if (caseFile() || lineUp()) {
            outlines ??= GuessrMap.load(live.dataset.boundaries);
        } else {
            slot('mode-rule').textContent = standingMode()
                ? t("This game is Last One Standing: a wrong answer, or none, and you're out; if everyone still in gets it wrong, they all stay in. The last one in wins.")
                : t('This game is Classic: everyone plays every round, and the most points win.');
        }

        // Skyline's guess can be 0.
        if (state.guess !== null && state.guess !== undefined) {
            // As after reloading the page mid-round.
            guessed = { round: state.round, guess: state.guess };
        }

        // Case File's clues as they open, and what a right accusation is worth now.
        live.classList.toggle('is-case', caseFile());
        live.classList.toggle('is-lineup', lineUp());
        if (caseFile() && caseScreen && dealt === state.round && state.phase === 'question') {
            caseScreen.clues(state.clues);
            caseScreen.hud({ worth: state.worth });
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

    // DistrictGuessr's district, and the map, cleared and framed (on Easy, zoomed into the district's state), with the
    // pin already confirmed, as after reloading.
    const setOut = () => {
        const round = view.round;
        const mine = view.pin;
        const region = view.district.region;
        const easy = view.difficulty === 'easy';

        slot('district').textContent = view.district.name;
        slot('region').textContent = view.district.place;
        slot('foot').hidden = false;
        slot('confirm').disabled = true;

        drawing ??= GuessrMap.draw(slot('map'), outlines ?? GuessrMap.load(live.dataset.boundaries)).then((drawn) => {
            drawn.map.on('click', (event) => drop(drawn.map, event.latlng));
            return drawn;
        });

        placed = drawing.then((drawn) => {
            const { map } = drawn;

            if (dealt !== round) {
                return;
            }

            [pin, ...marks].forEach((layer) => layer?.remove());
            pin = mine ? L.marker([mine.lat, mine.lng], { icon: GuessrMap.pin('guess'), keyboard: false }).addTo(map) : null;
            marks = [];
            map.invalidateSize();
            GuessrMap.frame(drawn, region, easy, { paddingTopLeft: [12, 12], paddingBottomRight: [12, 90] });
        });

        // The whole map in view, with Confirm, as on a phone, where the title takes the top of the screen: the round from
        // its timer down, just under the bar along the top, which stays in place.
        if (slot('map').getBoundingClientRect().bottom > window.innerHeight) {
            const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
            window.scrollTo({ top: window.scrollY + part('round').getBoundingClientRect().top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // Skyline's towers, the glass one back on the ground, or at the guess already made, as after reloading; locked once
    // the round's not taking a guess from this phone.
    const setSkyline = () => {
        const round = view.round;
        sky ??= SkylineRound.create(slot('skyline').querySelector('[data-sky-round]'), {
            outlines: outlines ?? GuessrMap.load(live.dataset.boundaries),
            onConfirm: () => answer('height'),
        });

        shown = sky.show(view.skyline).then(() => {
            if (dealt !== round || !view) {
                return;
            }

            if (answered()) {
                sky.set(guessed.guess);
            }

            if (answered() || sittingOut() || revealed === round || serverNow() > view.closesAt) {
                sky.lock();
            }
        });

        // The towers and the slider in view, as on a phone, where the title takes the top of the screen: the round from
        // its timer down, just under the bar along the top, which stays in place.
        const controls = slot('skyline').querySelector('[data-sky="controls"]');
        if (controls.getBoundingClientRect().bottom > window.innerHeight) {
            const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
            window.scrollTo({ top: window.scrollY + part('round').getBoundingClientRect().top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // Case File's case: its suspects, none picked, and the clues open so far, or the pick already made, as after
    // reloading.
    const setCase = () => {
        caseScreen ??= CaseScreen.create(slot('case').querySelector('[data-case-screen]'), {
            outlines: outlines ?? GuessrMap.load(live.dataset.boundaries),
            onAccuse: () => answer('suspect'),
        });
        caseScreen.show({ suspects: view.suspects, clues: view.clues, worth: view.worth });
        caseScreen.hud({ label: t('Round :round of :rounds', { round: view.round, rounds: view.rounds }), time: view.seconds, score: null });

        if (answered()) {
            caseScreen.choose(guessed.guess);
        } else if (!sittingOut()) {
            caseScreen.say(t('A new case! Its rap sheet is open; the other clues open one by one. The suspects are on the corkboard.'), [
                { label: t('Read the rap sheet'), primary: true, action: () => { caseScreen.hush(); caseScreen.look('rap'); } },
                { label: t('Suspects'), action: () => { caseScreen.hush(); caseScreen.view('board'); } },
            ]);
        }

        // The whole screen in view, just under the bar along the top, which stays in place.
        const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
        live.style.setProperty('--bar', `${bar}px`);
        const top = slot('case').getBoundingClientRect().top;
        if (Math.abs(top - bar - 8) > 4) {
            window.scrollTo({ top: window.scrollY + top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // Line-Up's suspects as they first stand, none moved, or in the order already given, as after reloading; the crime,
    // state and year over them.
    const setLineUp = () => {
        lineUpRoom ??= LineUpRoom.create(slot('lineup').querySelector('[data-lu-room]'), { outlines: outlines ?? GuessrMap.load(live.dataset.boundaries) });
        slot('lu-icon').textContent = view.lineup.icon;
        slot('lu-type').textContent = view.lineup.type;
        slot('lu-state').textContent = view.lineup.state;
        slot('lu-year').textContent = view.lineup.year;
        lineUpRoom.show(view.lineup);
        if (answered()) {
            lineUpRoom.set(guessed.guess);
        }
        luControl('confirm').hidden = false;
        luControl('confirm').disabled = false;

        // The room and Confirm in view, just under the bar along the top, which stays in place.
        if (luControl('confirm').getBoundingClientRect().bottom > window.innerHeight) {
            const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
            window.scrollTo({ top: window.scrollY + part('round').getBoundingClientRect().top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // The answer as it shows while waiting for the others: Skyline's is the height, Case File's the suspect accused,
    // Line-Up's the order.
    const lockedIn = (guess) => {
        if (lineUp()) {
            return t('Line-up given. Waiting for the others…');
        }

        if (caseFile()) {
            return t('Accused: :name. Waiting for the others…', { name: view.suspects[guess]?.name ?? '' });
        }

        if (typeof guess === 'number') {
            return t('Locked in: :count. Waiting for the others…', { count: number.format(guess) });
        }

        return guess === 'pin'
            ? t('Pinned. Waiting for the others…')
            : t('Locked in: :guess. Waiting for the others…', { guess: guess === 'higher' ? t('Higher') : t('Lower') });
    };

    // The round, once each round.
    const deal = () => {
        show('round');

        if (dealt === view.round) {
            return;
        }

        dealt = view.round;
        // Once a round opens, not again for its answer after reloading.
        if (['ready', 'question'].includes(view.phase)) {
            window.CrimeifyAwake?.buzz(40);
        }
        slot('round').textContent = t('Round :round of :rounds', { round: view.round, rounds: view.rounds })
            + (pins() ? ` · ${view.difficulty === 'easy' ? t('Easy') : t('Hard')}` : '');
        say('standing', '');

        if (pins()) {
            setOut();
        } else if (skyline()) {
            setSkyline();
        } else if (caseFile()) {
            setCase();
        } else if (lineUp()) {
            setLineUp();
        } else {
            dealCards();
        }

        if (sittingOut()) {
            lock();
            say('note', view.out === 0 ? t("This game had already started: you're watching.") : t("You're out: watch how it ends."));
        } else if (answered()) {
            lock(guessed.guess);
            say('note', lockedIn(guessed.guess));
        } else {
            say('note', '');
            slot('cards').querySelector('[data-guess="higher"]')?.focus({ preventScroll: true });
        }
    };

    // No more answering: the buttons, the pin and Confirm, which goes, or the slider, once the towers are set out.
    const lock = (guess = null) => {
        if (caseFile()) {
            caseScreen?.lock();
            return;
        }

        if (lineUp()) {
            lineUpRoom?.lock();
            luControl('confirm').disabled = true;
            return;
        }

        if (skyline()) {
            const round = view.round;
            shown = shown.then(() => {
                if (dealt === round) {
                    sky.lock();
                }
            });
            return;
        }

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
        if (caseFile()) {
            caseScreen?.unlock();
            return;
        }

        if (lineUp()) {
            lineUpRoom?.unlock();
            luControl('confirm').disabled = false;
            return;
        }

        if (skyline()) {
            shown = shown.then(() => sky.unlock());
            return;
        }

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
        if (caseFile()) {
            caseScreen?.hud({ time: Math.ceil(left / 1000) });
            caseScreen?.clock(left / 1000, view.seconds);
        }
        slot('bar').style.transform = `scaleX(${left / (view.seconds * 1000)})`;
        live.classList.toggle('is-hurrying', left > 0 && left <= 3000);

        if (left > 0 || answered() || sending || sittingOut()) {
            return;
        }

        if (pins() && pin && now <= view.closesAt + 1000) {
            answer('pin', true);
        } else if (skyline() && sky?.value() !== null && sky?.value() !== undefined && now <= view.closesAt + 1000) {
            answer('height', true);
        } else if (caseFile() && caseScreen?.chosen() !== null && caseScreen?.chosen() !== undefined && now <= view.closesAt + 1000) {
            answer('suspect', true);
        } else if (lineUp() && lineUpRoom?.touched() && now <= view.closesAt + 1000) {
            // A line-up moved counts as it stands; one never moved, as none.
            answer('order', true);
        } else {
            lock();
            say('note', t("Time's up. The answer's coming…"));
        }
    };

    // The answer: higher or lower, the pin, Skyline's height, Case File's suspect or Line-Up's order, as it is when the
    // time's up.
    const answer = async (guess, late = false) => {
        const now = serverNow();
        const at = guess === 'pin' ? pin?.getLatLng() : null;
        const height = guess === 'height' ? (sky?.value() ?? null) : null;
        const suspect = guess === 'suspect' ? (caseScreen?.chosen() ?? null) : null;
        const order = guess === 'order' ? (lineUpRoom?.order() ?? null) : null;

        if (sending || answered() || !view || revealed === view.round || (guess === 'pin' && !at) || (guess === 'height' && height === null)
            || (guess === 'suspect' && suspect === null) || (guess === 'order' && order === null) || sittingOut() || now < view.opensAt - 500 || now > view.closesAt + (late ? 1000 : 0)) {
            return;
        }

        const mine = { height, suspect, order }[guess] ?? guess;
        sending = true;
        guessed = { round: view.round, guess: mine };
        lock(mine);
        say('note', lockedIn(mine));

        try {
            const body = at ? { lat: at.lat, lng: at.lng } : guess === 'height' ? { value: height } : guess === 'suspect' ? { suspect } : guess === 'order' ? { order } : { guess };
            await request(`/${me.game}/answer`, { round: view.round, ...body });
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

        // Last One Standing: still out or watching; all saved, as everyone still in got it wrong; out this round; or in.
        if (standingMode() && sittingOut()) {
            say('note', state.out === 0 ? t("This game had already started: you're watching.") : t("You're out: watch how it ends."));
        } else if (standingMode() && state.saved) {
            say('note', t('It was :answer. Everyone still in got it wrong, so you all stay in!', { answer: was }), 'right');
        } else if (standingMode() && state.out === state.round) {
            say('note', result === null ? t("No answer: you're out. It was :answer.", { answer: was }) : t("Wrong: it was :answer. You're out!", { answer: was }), 'wrong');
        } else if (standingMode()) {
            say('note', t('Right! +:points points. Still in!', { points: number.format(result.points) }), 'right');
        } else if (result === null) {
            say('note', t('No answer this round. It was :answer.', { answer: was }), 'wrong');
        } else if (result.correct) {
            say('note', t('Right! +:points points', { points: number.format(result.points) }), 'right');
        } else {
            say('note', t('Wrong: it was :answer.', { answer: was }), 'wrong');
        }
    };

    // Where the player stands: in Last One Standing, still in or the round they went out in; otherwise points and place.
    const standingText = (state) => {
        if (!standingMode()) {
            return t('Your points: :score · Place :rank of :count', { score: number.format(state.score), rank: state.rank, count: state.players });
        }

        if (state.out === null) {
            return t('Still in · :left of :count left', { left: state.left, count: state.players });
        }

        return state.out === 0
            ? t('Watching · :left of :count still in', { left: state.left, count: state.players })
            : t('Out in round :round · Place :rank of :count', { round: state.out, rank: state.rank, count: state.players });
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

    // Skyline's answer: the real tower grows inside the glass one, with what it was, how far off the guess was, and its
    // points.
    const showHeight = (state) => {
        const result = state.result;
        const was = number.format(state.answer);

        if (result === null) {
            say('note', t('No answer this round. It was :answer.', { answer: was }), 'wrong');
        } else {
            const off = Math.round((Math.abs(state.guess - state.answer) / Math.max(state.answer, 1)) * 100);
            const scored = number.format(result.points);
            const text = off <= Number(live.dataset.skylinePerfect)
                ? t('Spot on! It was :count · :points points', { count: was, points: scored })
                : tn(result.points, 'It was :count: :off% off · :points point', 'It was :count: :off% off · :points points', { count: was, off, points: scored });
            say('note', text, result.points >= 500 ? 'right' : result.points > 0 ? 'near' : 'wrong');
        }

        const round = state.round;
        shown = shown.then(() => (dealt === round ? sky.reveal({ crimes: state.answer, guess: result ? state.guess : null }) : null));
    };

    // Case File's answer: every clue open, the stamp, the district marked and a wrong accusation crossed out, and how
    // the player did.
    const showCase = (state) => {
        const result = state.result;
        const district = state.suspects[state.answer]?.name ?? '';
        const text = result === null
            ? t('No answer this round. It was :answer.', { answer: district })
            : result.correct
                ? t('Case solved! It was :district · +:points points', { district, points: number.format(result.points) })
                : t('Wrong suspect: it was :district.', { district });

        caseScreen?.reveal({ culprit: state.answer, suspect: result ? state.guess : null, clues: state.clues, text: `${text} ${standingText(state)}` });
    };

    // Line-Up's answer: each suspect on a riser to its count, the right places stamped on the floor, against the order
    // given (with none, they step into the right one), and how it did.
    const showLineUp = (state) => {
        const result = state.result;
        const given = result ? state.guess : null;
        luControl('confirm').hidden = true;

        if (!given) {
            say('note', t("Time's up, with no line-up: here's the right order."), 'wrong');
        } else {
            const offs = given.map((suspect, at) => Math.abs(state.answer.indexOf(suspect) - at));
            const right = offs.filter((off) => off === 0).length;
            const near = offs.filter((off) => off === 1).length;
            const points = number.format(result.points);
            say('note', right === offs.length
                ? t('Perfect line-up! +:points points', { points })
                : t(':right in the right place, :near one place off · +:points points', { right, near, points }),
            result.points >= 500 ? 'right' : result.points > 0 ? 'near' : 'wrong');
        }

        lineUpRoom?.reveal({ crimes: state.crimes, truth: state.answer, given });
    };

    // The round's answer, once, and how this player did, with their points and place.
    const reveal = (state) => {
        deal();
        say('standing', standingText(state));

        if (revealed === state.round) {
            return;
        }

        revealed = state.round;
        window.CrimeifyAwake?.buzz([60, 80, 60]);
        live.classList.remove('is-hurrying');
        slot('seconds').textContent = 0;
        slot('bar').style.transform = 'scaleX(0)';
        lock(answered() ? guessed.guess : null);

        if (pins()) {
            showPlace(state);
        } else if (skyline()) {
            showHeight(state);
        } else if (caseFile()) {
            showCase(state);
        } else if (lineUp()) {
            showLineUp(state);
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

        // DistrictGuessr's awards, this player's own marked.
        const awards = GuessrMap.awards(state.awards ?? []);
        slot('awards').replaceChildren(...awards);
        slot('awards-panel').hidden = awards.length === 0;

        const rows = [...state.leaderboard];
        if (!rows.some((row) => row.you)) {
            rows.push(null, { rank: state.rank, name: state.name, score: state.score, you: true, ...(standingMode() ? { out: state.out } : {}) });
        }

        slot('board').replaceChildren(...rows.map((row) => {
            const item = document.createElement('li');

            if (row === null) {
                item.className = 'live-board-gap';
                item.textContent = '…';
                return item;
            }

            // In Last One Standing, still in, or the round they went out in.
            const tag = !('out' in row) ? null : row.out === null ? t('Still in') : row.out === 0 ? t('Watching') : t('Out in round :round', { round: row.out });
            item.className = `live-board-row${row.you ? ' is-you' : ''}${row.rank <= 3 ? ` is-top-${row.rank}` : ''}${tag !== null && row.out !== null ? ' is-out' : ''}`;
            for (const [kind, text] of [['rank', row.rank], ['name', row.name], ['tag', tag], ['score', number.format(row.score)]]) {
                if (text === null) {
                    continue;
                }
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
    luControl('confirm').addEventListener('click', () => answer('order'));

    // ↑ for higher and ↓ for lower, while the round's cards wait for an answer; Enter confirms the pin, or Skyline's
    // height (whose slider has the arrows).
    document.addEventListener('keydown', (event) => {
        if (part('round').hidden || answered() || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const outside = !event.target.closest?.('a, button, input:not([type="range"]), summary');
        let guess = { ArrowUp: 'higher', ArrowDown: 'lower' }[event.key];

        if (pins()) {
            guess = event.key === 'Enter' && !slot('confirm').disabled && outside ? 'pin' : null;
        } else if (skyline()) {
            guess = event.key === 'Enter' && sky?.value() !== null && outside ? 'height' : null;
        } else if (caseFile()) {
            // Accuse takes a tap, as a pick can be changed until then.
            guess = null;
        } else if (lineUp()) {
            // Not on a suspect, where Enter picks it up.
            guess = event.key === 'Enter' && outside && !luControl('confirm').disabled ? 'order' : null;
        }

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
