// A live game's big screen (LiveGameController): checks where the game is up to about every second and shows it for the
// room: the players as they join, a few seconds to get ready for each round, its cards with the time left and how many
// have answered, then its answer, how many said higher and lower, and the top 5, and at the end the podium and everyone.
// Start, Show the answer, Next round and End the game now ask the server to move it on. Rounds open on the server's
// clock, worked out from each check, as on the players' phones.
(() => {
    const host = document.querySelector('[data-live-host]');

    if (!host) {
        return;
    }

    const template = host.querySelector('template[data-game-card]');
    const part = (name) => host.querySelector(`[data-host-part="${name}"]`);
    const slot = (name) => host.querySelector(`[data-host="${name}"]`);
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

    // The game as last checked, the server's clock less this one's, the round whose cards are showing and the one whose
    // answer is, and a button's request on its way.
    let view = null;
    let offset = 0;
    let checking = null;
    let dealt = null;
    let revealed = null;
    let busy = false;

    const serverNow = () => Date.now() + offset;

    const request = async (url, post = false) => {
        const sent = Date.now();
        const response = await fetch(url, {
            method: post ? 'POST' : 'GET',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': host.dataset.token, 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error(`The game answered ${response.status}.`);
        }

        const state = await response.json();
        offset = state.now - (sent + Date.now()) / 2;

        return state;
    };

    const show = (name) => {
        for (const section of host.querySelectorAll('[data-host-part]')) {
            section.hidden = section.dataset.hostPart !== name;
        }
    };

    const check = async () => {
        clearTimeout(checking);

        try {
            render(await request(host.dataset.state));
        } catch {
            // A dropped connection or a busy server: the next check tries again.
        }

        if (view?.phase !== 'finished') {
            checking = setTimeout(check, 1000);
        }
    };

    // A button: start, next round, show the answer, or end the game.
    const act = async (action) => {
        if (busy) {
            return;
        }

        if (action === 'finish' && !window.confirm('End the game now? Its leaderboard will be the points so far.')) {
            return;
        }

        busy = true;
        host.querySelectorAll('[data-host-action]').forEach((button) => { button.disabled = true; });

        try {
            render(await request(host.dataset[action], true));
        } catch {
            // The next check shows where it's up to.
        } finally {
            busy = false;
            host.querySelectorAll('[data-host-action]').forEach((button) => { button.disabled = false; });
            if (view?.phase === 'lobby') {
                part('lobby').querySelector('[data-host-action]').disabled = view.players === 0;
            }
        }
    };

    const render = (state) => {
        view = state;
        host.dataset.phase = state.phase;
        slot('foot').hidden = state.phase === 'finished';
        slot('join').hidden = state.phase === 'finished';

        if (state.phase === 'lobby') {
            show('lobby');

            // Only the names that are new, so each comes in once rather than every check.
            const shown = [...slot('names').children].map((chip) => chip.textContent);
            slot('names').append(...state.names.filter((name) => !shown.includes(name)).map((name) => {
                const chip = document.createElement('li');
                chip.textContent = name;
                return chip;
            }));
            slot('players').textContent = state.players === 0 ? 'No one has joined yet.' : `${state.players} ${state.players === 1 ? 'player' : 'players'} in`;
            part('lobby').querySelector('[data-host-action]').disabled = state.players === 0 || busy;
        } else if (state.phase === 'reveal') {
            reveal(state);
        } else if (state.phase === 'finished') {
            finish(state);
        }

        tick();
    };

    const card = (face, kind) => {
        const element = template.content.firstElementChild.cloneNode(true);
        const field = (name) => element.querySelector(`[data-card="${name}"]`);

        field('icon').textContent = face.icon;
        field('type').textContent = face.type;
        field('district').textContent = face.district;
        field('place').textContent = face.place;
        field('year').textContent = face.year;
        field('count').hidden = false;
        // The one to guess shows a question mark until the answer.
        field('crimes').textContent = kind === 'shown' ? number.format(face.crimes) : '?';
        element.classList.add(`game-card-${kind}`);

        return element;
    };

    const deal = () => {
        show('round');

        if (dealt === view.round) {
            return;
        }

        dealt = view.round;
        slot('round').textContent = `Round ${view.round} of ${view.rounds}`;
        slot('top-panel').hidden = true;

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

    // Getting ready, then the time left and how many have answered, to a tenth of a second.
    const tick = () => {
        if (!view || !['ready', 'question'].includes(view.phase)) {
            return;
        }

        const now = serverNow();

        if (now < view.opensAt) {
            show('ready');
            slot('ready-round').textContent = `Round ${view.round} of ${view.rounds}`;
            slot('countdown').textContent = Math.ceil((view.opensAt - now) / 1000);
            return;
        }

        deal();

        const left = Math.max(0, view.closesAt - now);
        slot('seconds').textContent = Math.ceil(left / 1000);
        slot('bar').style.transform = `scaleX(${left / (view.seconds * 1000)})`;
        host.classList.toggle('is-hurrying', left > 0 && left <= 3000);
        slot('note').textContent = `${view.answered} of ${view.players} answered`;
        part('round').querySelector('[data-host-action="close"]').hidden = false;
        part('round').querySelector('[data-host-action="next"]').hidden = true;
    };

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

    const board = (list, rows) => {
        list.replaceChildren(...rows.map((row) => {
            const item = document.createElement('li');
            item.className = `live-board-row${row.rank <= 3 ? ` is-top-${row.rank}` : ''}`;

            for (const [kind, text] of [['rank', row.rank], ['name', row.name], ['score', number.format(row.score)]]) {
                const cell = document.createElement('span');
                cell.className = `live-board-${kind}`;
                cell.textContent = text;
                item.append(cell);
            }

            return item;
        }));
    };

    // The round's answer, once: the count that was hidden, how many said which, and the top 5.
    const reveal = (state) => {
        deal();
        host.classList.remove('is-hurrying');
        part('round').querySelector('[data-host-action="close"]').hidden = true;
        part('round').querySelector('[data-host-action="next"]').hidden = false;
        slot('next-label').textContent = state.last ? 'See the final results' : 'Next round';

        if (revealed === state.round) {
            return;
        }

        revealed = state.round;
        slot('seconds').textContent = 0;
        slot('bar').style.transform = 'scaleX(0)';

        const next = slot('cards').querySelector('.game-card-next');
        next.classList.add('is-right');
        countUp(next.querySelector('[data-card="crimes"]'), state.answer);

        slot('note').textContent = `It was ${state.right}! ${state.said.higher} said higher, ${state.said.lower} said lower.`;
        board(slot('top'), state.leaderboard);
        slot('top-panel').hidden = state.leaderboard.length === 0;
    };

    // The end: the top 3 on the podium, second, first and third, and everyone under it.
    const finish = (state) => {
        clearTimeout(checking);

        if (!part('finished').hidden) {
            return;
        }

        show('finished');
        const [first, second, third] = state.leaderboard;
        slot('podium').replaceChildren(...[second, first, third].filter(Boolean).map((row) => {
            const step = document.createElement('li');
            step.className = `live-podium-step is-top-${row.rank}`;

            for (const [kind, text] of [['name', row.name], ['score', `${number.format(row.score)} points`], ['rank', row.rank]]) {
                const cell = document.createElement('span');
                cell.className = `live-podium-${kind}`;
                cell.textContent = text;
                step.append(cell);
            }

            return step;
        }));
        board(slot('board'), state.leaderboard);
    };

    host.querySelectorAll('[data-host-action]').forEach((button) => {
        button.addEventListener('click', () => act(button.dataset.hostAction));
    });

    setInterval(tick, 100);
    check();
})();
