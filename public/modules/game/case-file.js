// Case File (CaseFileController), played in its pixel screen (case-screen.js): the case file's cover to start on, with
// the name; a short countdown over the office; then each case, its rap sheet open close up to start with, the other
// clues on the desk to open at their price (the server says what each found), and the corkboard's suspects to pick and
// accuse, which the server answers with who it was, the points and every clue. When the time runs out, the case
// closes with no one accused. After the fifth, the closing report. The name is remembered in the browser.
(() => {
    const game = document.querySelector('[data-case-file]');

    if (!game || !window.CaseScreen) {
        return;
    }

    const slot = (name) => game.querySelector(`[data-case-slot="${name}"]`);
    const button = (name) => game.querySelector(`[data-case-action="${name}"]`);
    const form = game.querySelector('[data-case-start]');
    const seconds = Number(game.dataset.seconds);
    const cases = Number(game.dataset.cases);
    const points = Number(game.dataset.points);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nameKey = 'crimeify.game.name';

    // The states' outlines, fetched as the page loads, for the photo clue.
    const outlines = fetch(game.dataset.boundaries).then((response) => response.json()).catch(() => null);

    // The game, the case being solved (none between its pick and the next), the clock, and a request on its way.
    let token = null;
    let asked = null;
    let score = 0;
    let summary = null;
    let clock = null;
    let busy = false;

    const screen = CaseScreen.create(game.querySelector('[data-case-screen]'), {
        outlines,
        onOpen: (clue) => openClue(clue),
        onAccuse: (suspect) => accuse(suspect),
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

    // The whole screen in view, just under the bar along the top, which stays in place.
    const frame = () => {
        const bar = document.querySelector('.public-topbar')?.getBoundingClientRect().bottom ?? 0;
        game.style.setProperty('--bar', `${bar}px`);
        const top = game.getBoundingClientRect().top;
        if (Math.abs(top - bar - 8) > 4) {
            window.scrollTo({ top: window.scrollY + top - bar - 8, behavior: calm ? 'auto' : 'smooth' });
        }
    };

    // The time left, in the strip along the top and on the clock on the wall. At none, the case closes unsolved.
    const startClock = () => {
        const end = performance.now() + seconds * 1000;

        const tick = () => {
            const left = Math.max(0, (end - performance.now()) / 1000);
            screen.hud({ time: Math.ceil(left) });
            screen.clock(left, seconds);

            if (left <= 0) {
                accuse(null);
            }
        };

        tick();
        clock = setInterval(tick, 250);
    };

    const stopClock = () => clearInterval(clock);

    // A case: the office, its number, and its rap sheet close up, with where to go next.
    const ask = (next) => {
        asked = next;
        screen.show(next, next.case);
        screen.hud({ label: t('Case :case of :cases', { case: next.case, cases }), worth: next.worth, score, time: seconds });
        screen.clock(seconds, seconds);
        screen.look('rap');
        screen.say(t('Case :case. Its rap sheet is open: more clues are on the desk, and the suspects on the corkboard.', { case: next.case }), [
            { label: t('To the desk'), primary: true, action: () => { screen.hush(); screen.view('room'); } },
            { label: t('Suspects'), action: () => { screen.hush(); screen.view('board'); } },
        ]);
        startClock();
    };

    // The seconds before the first case, counting down over the office. Resolves when they're up.
    const countdown = (wait) => new Promise((resolve) => {
        const end = Date.now() + wait * 1000;
        screen.view('room');
        screen.hud({ label: '', worth: null, score: null, time: seconds });

        const step = () => {
            const left = Math.ceil((end - Date.now()) / 1000);

            if (left <= 0) {
                screen.countdown(null);
                resolve();
                return;
            }

            screen.countdown(left);
            setTimeout(step, 100);
        };

        step();
    });

    const start = async () => {
        if (busy) {
            return;
        }

        const name = form.elements.name.value.trim();
        const error = game.querySelector('[data-case-error]');
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
            frame();
            await countdown(started.ready);
            busy = false;
            ask(started.asked);
        } catch (problem) {
            busy = false;
            screen.view('cover');
            error.textContent = problem.message;
            error.hidden = false;
            form.elements.name.focus();
        }
    };

    // A clue opened: what it found, close up, and what the case is worth now.
    const openClue = async (clue) => {
        if (!asked || busy) {
            return;
        }

        busy = true;

        try {
            const opened = await post(game.dataset.clue, { token, clue });
            screen.open(opened.clue, opened.said, { looking: true });
            screen.hud({ worth: opened.worth });
        } catch (problem) {
            screen.say(problem.message, [{ label: t('OK'), primary: true, action: () => screen.hush() }]);
        } finally {
            busy = false;
        }
    };

    // The pick, or none when the time's up: who it was, the points, and every clue.
    const accuse = async (suspect) => {
        if (!asked || busy) {
            return;
        }

        busy = true;
        stopClock();
        screen.lock();

        let answer;

        try {
            answer = await post(game.dataset.accuse, { token, suspect });
        } catch (problem) {
            busy = false;
            screen.unlock();
            screen.say(problem.message, [{ label: t('OK'), primary: true, action: () => screen.hush() }]);
            return;
        }

        const district = asked.suspects[answer.culprit].name;
        asked = null;
        busy = false;
        score = answer.score;
        summary = answer.over ? answer.summary : null;

        const text = answer.right
            ? t('Case solved! It was :district · +:points points', { district, points: number.format(answer.points) })
            : answer.suspect === null
                ? t("Time's up, with no one accused. It was :district.", { district })
                : t('Wrong suspect: it was :district.', { district });
        screen.hud({ score });
        screen.reveal({
            culprit: answer.culprit,
            suspect: answer.suspect,
            clues: answer.clues,
            text,
            choices: [
                { label: answer.over ? t('See the report') : t('Next case'), primary: true, action: next },
                { label: t('Look at the desk'), action: () => { screen.hush(); screen.view('room'); } },
            ],
        });
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
        screen.hush();

        try {
            const followed = await post(game.dataset.next, { token });
            busy = false;
            ask(followed.asked);
        } catch (problem) {
            busy = false;
            screen.say(problem.message, [{ label: t('Try again'), primary: true, action: next }]);
        }
    };

    // The end: the closing report, every case's district, whether it was solved and with how many clues, and its
    // points; and the score out of the most there is.
    const over = () => {
        screen.hush();
        screen.view('report');
        slot('final').textContent = number.format(score);
        slot('out-of').textContent = t('out of :most', { most: number.format(cases * points) });
        slot('summary').replaceChildren(...summary.map((row) => {
            const item = document.createElement('li');
            item.className = row.right ? 'is-solved' : 'is-unsolved';
            const how = row.right ? tn(row.clues, 'Solved with :count clue', 'Solved with :count clues') : t('Not solved');

            for (const [kind, text] of [['name', row.district], ['state', row.state], ['how', how], ['points', number.format(row.points)]]) {
                const cell = document.createElement('span');
                cell.className = `case-summary-${kind}`;
                cell.textContent = text;
                item.append(cell);
            }

            return item;
        }));
        document.getElementById('case-over-heading').focus({ preventScroll: true });
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    button('again').addEventListener('click', () => (form.elements.name.value.trim() === '' ? screen.view('cover') : start()));
    button('rename').addEventListener('click', () => {
        screen.view('cover');
        form.elements.name.focus();
    });

    screen.view('cover');
})();
