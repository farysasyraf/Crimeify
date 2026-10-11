// A live game of Higher or Lower on a phone (LivePlayController, LiveGames): joins with the code on the big screen and a
// name, then checks where the game is up to about every second and shows it: waiting for the host, a few seconds to get
// ready, the round's two cards with Higher and Lower until its time is up, then its answer with the player's points and
// place, and at the end the top 10. Each round opens on the server's clock, worked out from each check, so every phone
// shows the cards at the same moment. The game and the player are remembered in the tab, so reloading keeps them in it.
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
    // The round whose cards are showing, the one whose answer is, this player's guess ({ round, guess }), and an
    // answer on its way.
    let dealt = null;
    let revealed = null;
    let guessed = null;
    let sending = false;

    const serverNow = () => Date.now() + offset;
    const answered = () => guessed !== null && view !== null && guessed.round === view.round;

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

    const say = (name, text, kind = '') => {
        const element = slot(name);
        element.textContent = text;
        element.hidden = text === '';
        element.className = element.className.replace(/ ?is-(right|wrong)/g, '') + (kind ? ` is-${kind}` : '');
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

    // The round's cards, once each round.
    const deal = () => {
        show('round');

        if (dealt === view.round) {
            return;
        }

        dealt = view.round;
        slot('round').textContent = t('Round :round of :rounds', { round: view.round, rounds: view.rounds });

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
        say('standing', '');

        if (answered()) {
            lock(guessed.guess);
            say('note', t('Locked in: :guess. Waiting for the others…', { guess: guessed.guess === 'higher' ? t('Higher') : t('Lower') }));
        } else {
            say('note', '');
            second.querySelector('[data-guess="higher"]').focus({ preventScroll: true });
        }
    };

    const lock = (guess = null) => {
        slot('cards').querySelectorAll('[data-guess]').forEach((button) => {
            button.disabled = true;
            button.classList.toggle('is-chosen', button.dataset.guess === guess);
        });
    };

    // The time left to answer, to a tenth of a second, and getting ready before it.
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

        if (left === 0 && !answered()) {
            lock();
            say('note', t("Time's up. The answer's coming…"));
        }
    };

    const answer = async (guess) => {
        const now = serverNow();

        if (sending || answered() || !view || view.phase === 'reveal' || now < view.opensAt - 500 || now > view.closesAt) {
            return;
        }

        sending = true;
        guessed = { round: view.round, guess };
        lock(guess);
        say('note', t('Locked in: :guess. Waiting for the others…', { guess: guess === 'higher' ? t('Higher') : t('Lower') }));

        try {
            await request(`/${me.game}/answer`, { round: view.round, guess });
        } catch (problem) {
            // Not taken, unless the round has stopped taking answers or already has this one.
            if (problem.status !== 409) {
                guessed = null;
                slot('cards').querySelectorAll('[data-guess]').forEach((button) => {
                    button.disabled = false;
                    button.classList.remove('is-chosen');
                });
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

    // The round's answer, once: the count that was hidden, how this player did, and their points and place.
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

    // ↑ for higher and ↓ for lower, while the round's cards wait for an answer.
    document.addEventListener('keydown', (event) => {
        const guess = { ArrowUp: 'higher', ArrowDown: 'lower' }[event.key];

        if (!guess || part('round').hidden || answered() || event.altKey || event.ctrlKey || event.metaKey) {
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
