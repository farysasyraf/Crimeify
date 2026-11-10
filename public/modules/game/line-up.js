// Line-Up (LineUpController): the cover's clipboard starts a game with the name given; after a short countdown over the
// empty room, each round's five suspects come in (line-up-room.js), to put in order of the crime shown, most on the
// left. Confirm, or Enter, sends the order, and the server says the right one, every count and the points, as each
// suspect rises to its count. When the time runs out, the order counts as it stands, or as none if none was moved.
// After the last round, the witness statement. The name is remembered in the browser.
(() => {
    const game = document.querySelector('[data-line-up]');

    if (!game || !window.LineUpRoom) {
        return;
    }

    const part = (name) => game.querySelector(`[data-line-up-part="${name}"]`);
    const slot = (name) => game.querySelector(`[data-line-up="${name}"]`);
    const button = (name) => game.querySelector(`[data-line-up-action="${name}"]`);
    // The room's controls along its foot: Confirm, the next round, and what's said.
    const control = (name) => part('round').querySelector(`[data-lu="${name}"]`);
    const form = game.querySelector('[data-line-up-start]');
    const seconds = Number(game.dataset.seconds);
    const rounds = Number(game.dataset.rounds);
    const most = Number(game.dataset.most);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nameKey = 'crimeify.game.name';

    // The states' outlines, fetched as the page loads, for the map in the corner.
    const outlines = fetch(game.dataset.boundaries).then((response) => response.json()).catch(() => null);

    // The game, the round being ordered (none between its order and the next), the clock, and a request on its way.
    let token = null;
    let asked = null;
    let score = 0;
    let summary = null;
    let clock = null;
    let busy = false;

    const room = LineUpRoom.create(part('round').querySelector('[data-lu-room]'), { outlines });

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
        for (const section of game.querySelectorAll('[data-line-up-part]')) {
            section.hidden = section.dataset.lineUpPart !== name;
        }
    };

    // How the line-up did, in place of which way the order goes and how to move them; none puts those back.
    const result = (text, kind = '') => {
        control('result').textContent = text;
        control('result').hidden = text === '';
        control('result').className = `lu-result${kind ? ` is-${kind}` : ''}`;
        control('scale').hidden = text !== '';
        control('help').hidden = text !== '';
    };

    // The round from its strip down, just under the bar along the top, which stays in place.
    const frame = () => {
        const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
        const top = part('round').getBoundingClientRect().top;
        if (Math.abs(top - bar - 8) > 4) {
            window.scrollTo({ top: window.scrollY + top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // The time left: a bar that empties and the seconds, amber for the last few. At none, the order counts as it is.
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
                order(true);
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

    // A round: its crime, state and year in the strip, and its suspects in the room.
    const ask = (next) => {
        asked = next;
        slot('round').textContent = t('Round :round of :rounds', { round: next.round, rounds });
        slot('icon').textContent = next.icon;
        slot('type').textContent = next.type;
        slot('state').textContent = next.state;
        slot('year').textContent = next.year;
        result('');
        control('next').hidden = true;
        control('confirm').hidden = false;
        control('confirm').disabled = false;
        room.show(next);
        startClock();
    };

    // The seconds before the first round, counting down over the empty room. Resolves when they're up.
    const countdown = (wait) => new Promise((resolve) => {
        const end = Date.now() + wait * 1000;
        show('round');
        room.clear();
        slot('round').textContent = t('Round :round of :rounds', { round: 1, rounds });
        ['icon', 'type', 'state', 'year'].forEach((name) => { slot(name).textContent = ''; });
        control('confirm').disabled = true;
        result('');
        frame();

        const step = () => {
            const left = Math.ceil((end - Date.now()) / 1000);

            if (left <= 0) {
                room.countdown(null);
                resolve();
                return;
            }

            room.countdown(left);
            setTimeout(step, 100);
        };

        step();
    });

    const start = async () => {
        if (busy) {
            return;
        }

        const name = form.elements.name.value.trim();
        const error = game.querySelector('[data-line-up-error]');
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
            busy = false;
            ask(started.asked);
        } catch (problem) {
            busy = false;
            show('intro');
            error.textContent = problem.message;
            error.hidden = false;
            form.elements.name.focus();
        }
    };

    // How the order did, as the result says it.
    const said = (answer) => {
        if (answer.order === null) {
            return [t("Time's up, with no line-up: here's the right order."), 'wrong'];
        }

        const right = answer.offs.filter((off) => off === 0).length;
        const near = answer.offs.filter((off) => off === 1).length;
        const points = number.format(answer.points);

        if (right === answer.offs.length) {
            return [t('Perfect line-up! +:points points', { points }), 'right'];
        }

        return [t(':right in the right place, :near one place off · +:points points', { right, near, points }),
            answer.points >= 500 ? 'right' : answer.points > 0 ? 'near' : 'wrong'];
    };

    // The order as it stands, or when the time ran out and none was moved, none: its points, and the answer.
    const order = async (late = false) => {
        if (!asked || busy) {
            return;
        }

        busy = true;
        stopClock();
        room.lock();
        control('confirm').disabled = true;
        const given = late && !room.touched() ? null : room.order();

        let answer;

        try {
            answer = await post(game.dataset.order, { token, order: given });
        } catch (problem) {
            busy = false;
            room.unlock();
            control('confirm').disabled = false;
            result(problem.message, 'wrong');
            return;
        }

        asked = null;
        busy = false;
        score = answer.score;
        slot('score').textContent = number.format(score);
        result(...said(answer));
        room.reveal({ crimes: answer.crimes, truth: answer.truth, given: answer.order });

        if (answer.over) {
            summary = answer.rounds;
        }

        control('confirm').hidden = true;
        control('next-label').textContent = answer.over ? t('See the statement') : t('Next round');
        control('next').hidden = false;
        control('next').focus({ preventScroll: true });
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
        control('next').disabled = true;

        try {
            const followed = await post(game.dataset.next, { token });
            busy = false;
            ask(followed.asked);
        } catch (problem) {
            busy = false;
            result(problem.message, 'wrong');
        } finally {
            control('next').disabled = false;
        }
    };

    // The end, the witness statement: who gave it, every round's crime, state and year, its suspects in the right
    // order, each marked by where they stood in the line-up given, its points, and the score out of the most there is.
    const over = () => {
        show('over');
        slot('witness').textContent = form.elements.name.value.trim();
        slot('final').textContent = number.format(score);
        slot('out-of').textContent = t('out of :most', { most: number.format(rounds * most) });
        slot('rounds').replaceChildren(...summary.map((row, index) => {
            const item = document.createElement('li');
            item.className = 'lu-statement-round';
            item.style.setProperty('--n', index);

            const head = document.createElement('p');
            head.className = 'lu-statement-ask';
            head.textContent = `${row.type} · ${row.state} · ${row.year}`;
            const points = document.createElement('span');
            points.className = 'lu-statement-points';
            points.textContent = number.format(row.points);
            head.append(points);

            const names = document.createElement('ol');
            names.className = 'lu-statement-order';
            names.append(...row.suspects.map((suspect) => {
                const name = document.createElement('li');
                name.className = suspect.off === null ? 'is-none' : suspect.off === 0 ? 'is-right' : suspect.off === 1 ? 'is-near' : 'is-wrong';
                name.textContent = suspect.name;
                const count = document.createElement('span');
                count.textContent = number.format(suspect.crimes);
                name.append(count);
                return name;
            }));

            item.append(head, names);
            return item;
        }));
        document.getElementById('line-up-over-heading').focus({ preventScroll: true });
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    control('confirm').addEventListener('click', () => order());
    control('next').addEventListener('click', next);
    button('again').addEventListener('click', () => (form.elements.name.value.trim() === '' ? show('intro') : start()));
    button('rename').addEventListener('click', () => {
        show('intro');
        form.elements.name.focus();
    });

    // Enter confirms the line-up, or goes on to the next round; not on a suspect, where it picks one up.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || part('round').hidden || event.target.closest?.('a, button, input, summary')) {
            return;
        }

        if (asked) {
            event.preventDefault();
            order();
        } else if (!control('next').hidden) {
            event.preventDefault();
            next();
        }
    });
})();
