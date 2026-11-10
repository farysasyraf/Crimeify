// Skyline (SkylineController): starts a game with the name given, then after a short countdown shows a state's police
// districts as 3D towers (skyline-round.js, skyline-scene.js), one a glass outline to raise with the slider to the
// height thought right; Confirm, or Enter, sends it, and the server says what it was and its points, as the real tower
// grows inside the glass. Next round asks for the next; after the last, the inspection report, with every round's
// towers and points. When the time runs out, the guess counts as it is, or none if it was never moved. The name is
// remembered in the browser. The cover, the countdown and the report are an architect's blueprint (skyline-sheet.css).
(() => {
    const game = document.querySelector('[data-skyline]');

    if (!game || !window.SkylineRound) {
        return;
    }

    const part = (name) => game.querySelector(`[data-skyline-part="${name}"]`);
    const slot = (name) => game.querySelector(`[data-skyline="${name}"]`);
    const button = (name) => game.querySelector(`[data-skyline-action="${name}"]`);
    const form = game.querySelector('[data-skyline-start]');
    const seconds = Number(game.dataset.seconds);
    const rounds = Number(game.dataset.rounds);
    const points = Number(game.dataset.points);
    const perfect = Number(game.dataset.perfect);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nameKey = 'crimeify.game.name';

    // The states' outlines and the scene's module, fetched as the page loads, so they're ready when the game starts.
    const outlines = fetch(game.dataset.boundaries).then((response) => response.json()).catch(() => null);
    SkylineRound.load();

    // The game, the round being guessed (none between its guess and the next), the clock, and a request on its way.
    let token = null;
    let asked = null;
    let score = 0;
    let summary = null;
    let clock = null;
    let busy = false;

    const round = SkylineRound.create(part('round').querySelector('[data-sky-round]'), {
        outlines,
        onConfirm: () => guess(),
    });

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
        for (const section of game.querySelectorAll('[data-skyline-part]')) {
            section.hidden = section.dataset.skylinePart !== name;
        }
    };

    const result = (text, kind = '') => {
        slot('result').textContent = text;
        slot('result').className = `guessr-result${kind ? ` is-${kind}` : ''}`;
    };

    // The time left: a bar that empties and the seconds, amber for the last few. At none, the guess counts as it is.
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

    const ask = async (next) => {
        asked = next;
        busy = true;
        slot('round').textContent = t('Round :round of :rounds', { round: next.round, rounds });
        result('');
        button('next').hidden = true;
        await round.show(next);
        busy = false;

        // The towers and the slider in view, as on a phone, where the title takes the top of the screen: the round from
        // its timer down, just under the bar along the top, which stays in place.
        const controls = part('round').querySelector('[data-sky="controls"]');
        if (controls.getBoundingClientRect().bottom > window.innerHeight) {
            const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
            window.scrollTo({ top: window.scrollY + part('round').getBoundingClientRect().top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }

        startClock();
    };

    // The seconds before the first round, counting down. Resolves when they're up.
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
        const error = game.querySelector('[data-skyline-error]');
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
            busy = false;
            await ask(started.asked);
        } catch (problem) {
            busy = false;
            show('intro');
            error.textContent = problem.message;
            error.hidden = false;
            form.elements.name.focus();
        }
    };

    // How close a guess was, as the result says it.
    const said = (answer) => {
        const was = number.format(answer.crimes);

        if (answer.guess === null) {
            return [t("Time's up, with no guess. It was :count.", { count: was }), 'wrong'];
        }

        const off = Math.round((Math.abs(answer.guess - answer.crimes) / Math.max(answer.crimes, 1)) * 100);
        const scored = number.format(answer.points);

        if (off <= perfect) {
            return [t('Spot on! It was :count · :points points', { count: was, points: scored }), 'right'];
        }

        return [tn(answer.points, 'It was :count: :off% off · :points point', 'It was :count: :off% off · :points points', { count: was, off, points: scored }),
            answer.points >= 500 ? 'right' : answer.points > 0 ? 'near' : 'wrong'];
    };

    // The guess as it is, or none, for the round: what it was, its points, and the real tower growing.
    const guess = async () => {
        if (!asked || busy) {
            return;
        }

        busy = true;
        stopClock();
        round.lock();
        const value = round.value();

        let answer;

        try {
            answer = await post(game.dataset.guess, { token, guess: value });
        } catch (problem) {
            busy = false;
            round.unlock();
            result(problem.message, 'wrong');
            return;
        }

        asked = null;
        score = answer.score;
        slot('score').textContent = number.format(score);
        result(...said(answer));
        await round.reveal({ crimes: answer.crimes, guess: answer.guess });
        busy = false;

        if (answer.over) {
            summary = answer.rounds;
        }

        slot('next-label').textContent = answer.over ? t('See your score') : t('Next round');
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
            const followed = await post(game.dataset.next, { token });
            busy = false;
            await ask(followed.asked);
        } catch (problem) {
            busy = false;
            result(problem.message, 'wrong');
        } finally {
            button('next').disabled = false;
        }
    };

    const element = (tag, className, text) => {
        const made = document.createElement(tag);
        made.className = className;
        if (text !== undefined) {
            made.textContent = text;
        }
        return made;
    };

    // The end, the inspection report: who built it, the score out of the most there is, and every round's: its number,
    // the district and its state, its tower as guessed (none for no guess) beside the real one, the taller of them
    // full height, the guess and the count in words, and its points, coloured by how close it was.
    const over = () => {
        show('over');
        slot('architect').textContent = form.elements.name.value.trim();
        slot('final').textContent = number.format(score);
        slot('out-of').textContent = t('out of :most', { most: number.format(rounds * points) });
        part('over').style.setProperty('--rows', summary.length);
        slot('rounds').replaceChildren(...summary.map((row, index) => {
            const off = row.guess === null ? null : Math.abs(row.guess - row.crimes) / Math.max(row.crimes, 1) * 100;
            const how = off !== null && off <= perfect ? 'right' : row.points >= 500 ? 'right' : row.points > 0 ? 'near' : 'wrong';
            const item = element('li', `sky-round is-${how}`);
            item.style.setProperty('--n', index);

            const place = element('span', 'sky-round-place');
            place.append(element('strong', '', row.district), element('span', '', row.state));

            const tallest = Math.max(row.crimes, row.guess ?? 0, 1);
            const towers = element('span', 'sky-round-towers');
            towers.setAttribute('aria-hidden', 'true');
            for (const [kind, value] of [['guess', row.guess], ['real', row.crimes]]) {
                if (value !== null) {
                    const bar = element('span', `sky-bar is-${kind}`);
                    bar.style.setProperty('--h', String(value / tallest));
                    bar.style.setProperty('--n', index);
                    towers.append(bar);
                }
            }

            const guessed = row.guess === null
                ? t('No guess · it was :count', { count: number.format(row.crimes) })
                : t(':guess guessed · it was :count', { guess: number.format(row.guess), count: number.format(row.crimes) });

            item.append(element('span', 'sky-round-number', index + 1), place, towers, element('span', 'sky-round-figures', guessed),
                element('span', 'sky-round-points', number.format(row.points)));
            return item;
        }));
        document.getElementById('skyline-over-heading').focus({ preventScroll: true });
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    button('next').addEventListener('click', next);
    button('again').addEventListener('click', () => (form.elements.name.value.trim() === '' ? show('intro') : start()));
    button('rename').addEventListener('click', () => {
        show('intro');
        form.elements.name.focus();
    });

    // Enter confirms the guess, or goes on to the next round.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || part('round').hidden || event.target.closest?.('a, button, input:not([type="range"]), summary')) {
            return;
        }

        if (asked && round.value() !== null) {
            event.preventDefault();
            guess();
        } else if (!button('next').hidden) {
            event.preventDefault();
            next();
        }
    });
})();
