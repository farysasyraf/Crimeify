// Higher or Lower (GameController): starts a game with the name given, then shows the card with its count beside the
// one to guess, of the same crime, with a clock running down. Higher, Lower, or the ↑ and ↓ keys send the guess; the
// server says whether it was right and what the count was, which counts up on the card. Right, and the next round's two
// cards come, of a crime chosen again; wrong, or out of time, and the game is over. A game starts with a few seconds
// counting down before its first cards, which the server times their answer from too. The server keeps the cards and
// the score, so this only shows them. The name is remembered in the browser for next time.
(() => {
    const game = document.querySelector('[data-game]');

    if (!game) {
        return;
    }

    const part = (name) => game.querySelector(`[data-game-${name}]`);
    const form = part('start');
    const template = game.querySelector('template[data-game-card]');
    const seconds = Number(game.dataset.seconds);
    const count = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nameKey = 'crimeify.game.name';
    // How long a revealed count stays before the next card, or the end of the game.
    const pause = { right: calm ? 800 : 1000, wrong: 1600 };

    let token = null;
    let shown = null;
    let next = null;
    // A guess on its way or being shown, and a game starting, so neither goes twice.
    let waiting = false;
    let starting = false;
    let clock = null;

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
        for (const section of ['intro', 'ready', 'board', 'over']) {
            part(section).hidden = section !== name;
        }
    };

    const say = (text) => {
        part('said').textContent = text;
    };

    // A card from the template: the one shown, with its count, or the one to guess, with Higher and Lower.
    const card = (face, kind) => {
        const element = template.content.firstElementChild.cloneNode(true);
        const slot = (name) => element.querySelector(`[data-card="${name}"]`);

        slot('icon').textContent = face.icon;
        slot('type').textContent = face.type;
        slot('district').textContent = face.district;
        slot('place').textContent = face.place;
        slot('year').textContent = face.year;
        element.classList.add(`game-card-${kind}`);

        if (kind === 'shown') {
            slot('count').hidden = false;
            slot('crimes').textContent = count.format(face.crimes);
        } else {
            slot('guess').hidden = false;
            slot('ask').textContent = t('Higher or lower than :count?', { count: count.format(shown.crimes) });
            element.querySelectorAll('[data-guess]').forEach((button) => {
                button.addEventListener('click', () => guess(button.dataset.guess));
            });
        }

        return element;
    };

    const deal = (entering) => {
        const versus = document.createElement('span');
        versus.className = 'game-vs';
        versus.setAttribute('aria-hidden', 'true');
        versus.textContent = 'VS';

        const first = card(shown, 'shown');
        const guessed = card(next, 'next');
        if (entering && !calm) {
            first.classList.add('is-entering');
            guessed.classList.add('is-entering');
        }

        part('cards').replaceChildren(first, versus, guessed);
        guessed.querySelector('[data-guess="higher"]').focus({ preventScroll: true });
        startClock();
    };

    // The time left to guess: a bar that empties and the seconds, amber for the last few. At none, the guess is none.
    const startClock = () => {
        const bar = part('timer-bar');
        const end = performance.now() + seconds * 1000;

        bar.style.transition = 'none';
        bar.style.transform = 'scaleX(1)';
        bar.getBoundingClientRect();
        bar.style.transition = `transform ${seconds}s linear`;
        bar.style.transform = 'scaleX(0)';

        const tick = () => {
            const left = Math.max(0, Math.ceil((end - performance.now()) / 1000));
            part('seconds').textContent = left;
            game.classList.toggle('is-hurrying', left <= 3);

            if (left === 0) {
                guess(null);
            }
        };

        tick();
        clock = setInterval(tick, 200);
    };

    const stopClock = () => {
        clearInterval(clock);
        const bar = part('timer-bar');
        bar.style.transform = getComputedStyle(bar).transform;
        bar.style.transition = 'none';
    };

    // The count counts up to the figure, unless motion is turned down.
    const countUp = (element, to) => {
        if (calm) {
            element.textContent = count.format(to);
            return;
        }

        const start = performance.now();
        const step = (now) => {
            const done = Math.min(1, (now - start) / 600);
            element.textContent = count.format(Math.round(to * (1 - (1 - done) ** 3)));

            if (done < 1) {
                requestAnimationFrame(step);
            }
        };

        requestAnimationFrame(step);
    };

    // The seconds before the first cards, counting down. Resolves when they're up.
    const countdown = (seconds) => new Promise((resolve) => {
        const end = Date.now() + seconds * 1000;
        show('ready');

        const step = () => {
            const left = Math.ceil((end - Date.now()) / 1000);

            if (left <= 0) {
                resolve();
                return;
            }

            part('countdown').textContent = left;
            setTimeout(step, 100);
        };

        step();
    });

    const start = async () => {
        if (starting) {
            return;
        }

        const name = form.elements.name.value.trim();
        const error = part('error');
        error.hidden = true;
        starting = true;

        try {
            const started = await post(form.action, { name });

            try {
                localStorage.setItem(nameKey, name);
            } catch {
                // Not remembered, then.
            }

            ({ token, shown, next } = started);
            waiting = false;
            part('score').textContent = started.score;
            say('');
            await countdown(started.ready);
            show('board');
            deal(false);
        } catch (problem) {
            show('intro');
            error.textContent = problem.message;
            error.hidden = false;
            form.elements.name.focus();
        } finally {
            starting = false;
        }
    };

    const over = (why) => {
        stopClock();
        part('over-why').textContent = why;
        part('final').textContent = part('score').textContent;
        show('over');
        part('over-title').focus({ preventScroll: true });
    };

    const guess = async (choice) => {
        if (waiting) {
            return;
        }

        waiting = true;
        stopClock();

        const guessed = part('cards').querySelector('.game-card-next');
        guessed.querySelectorAll('[data-guess]').forEach((button) => {
            button.disabled = true;
            button.classList.toggle('is-chosen', button.dataset.guess === choice);
        });

        let result;

        try {
            result = await post(game.dataset.guess, { token, guess: choice });
        } catch (problem) {
            over(problem.message);
            return;
        }

        // The count that was hidden, on the card, and whether the guess was right.
        const slot = (name) => guessed.querySelector(`[data-card="${name}"]`);
        slot('guess').hidden = true;
        slot('count').hidden = false;
        countUp(slot('crimes'), result.answer);
        guessed.classList.add(result.right ? 'is-right' : 'is-wrong');
        part('score').textContent = result.score;

        const said = { district: next.district, count: count.format(result.answer) };

        if (!result.right) {
            // Out of time here, or a guess that reached the server after its time.
            const why = choice === null
                ? t("Time's up: :district had :count.", said)
                : (result.late ? t('Too late: :district had :count.', said) : t('Wrong: :district had :count.', said));
            say(why);
            setTimeout(() => over(why), pause.wrong);
            return;
        }

        say(t('Right! :district had :count.', said));

        if (result.over) {
            setTimeout(() => over(t("That's every card there is. Well played!")), pause.right);
            return;
        }

        setTimeout(() => {
            ({ shown, next } = result);
            waiting = false;
            deal(true);
        }, pause.right);
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });

    part('again').addEventListener('click', () => {
        if (form.elements.name.value.trim() === '') {
            show('intro');
            form.elements.name.focus();
            return;
        }

        start();
    });

    part('rename').addEventListener('click', () => {
        show('intro');
        form.elements.name.focus();
    });

    // ↑ for higher and ↓ for lower, while a card waits for a guess.
    document.addEventListener('keydown', (event) => {
        const key = { ArrowUp: 'higher', ArrowDown: 'lower' }[event.key];

        if (!key || part('board').hidden || waiting || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        event.preventDefault();
        guess(key);
    });
})();
