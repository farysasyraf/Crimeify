// A live game's big screen (LiveGameController): checks where the game is up to about every second and shows it for the
// room: the players as they join, a few seconds to get ready for each round, its cards, its district on the map
// (guessr-map.js) or its state's towers (skyline-round.js) with the time left and how many have answered, then its answer
// (how many said higher and lower; where the district is and everyone's pins; the real tower growing through
// everyone's guesses, the 3 closest named; or the case's district, with how many picked each suspect and the quickest
// who got it, its clues having opened one by one in its pixel screen, case-screen.js) and the top 5, and at the end the podium, in
// DistrictGuessr its awards, and everyone. Start, Show the answer, Next round and End the game now ask the server to move it on. Rounds open on the
// server's clock, worked out from each check, as on the players' phones. The screen stays on until the game's over
// (awake.js).
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
    // DistrictGuessr's map, once drawn ({ map, malaysia }), what the answer puts on it, and the round's map once it's set
    // out, for the answer to wait for.
    const pins = host.dataset.boundaries !== undefined;
    const outlines = pins ? GuessrMap.load(host.dataset.boundaries) : null;
    let drawing = null;
    let marks = [];
    let placed = Promise.resolve();
    // Skyline's round, once made, its states' outlines, and the round's towers once they're set out.
    const skyline = host.dataset.skyline !== undefined;
    const skylineOutlines = skyline ? fetch(host.dataset.skyline).then((response) => response.json()).catch(() => null) : null;
    let sky = null;
    let shown = Promise.resolve();
    // Case File's screen, once made, and its states' outlines for the photo clue.
    const caseFile = host.dataset.caseFile !== undefined;
    const caseOutlines = caseFile ? fetch(host.dataset.caseFile).then((response) => response.json()).catch(() => null) : null;
    let caseScreen = null;
    host.classList.toggle('is-case', caseFile);

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
        window.CrimeifyAwake?.keep(state.phase !== 'finished');
        host.dataset.phase = state.phase;
        slot('foot').hidden = state.phase === 'finished';
        slot('join').hidden = state.phase === 'finished';

        // Case File's clues as they open, and what a right accusation is worth now.
        if (caseFile && caseScreen && dealt === state.round && state.phase === 'question') {
            caseScreen.clues(state.clues);
            caseScreen.hud({ worth: state.worth, extra: `${state.answered} of ${state.players} accused` });
        }

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
        slot('round').textContent = `Round ${view.round} of ${view.rounds}${pins ? ` · ${view.difficulty === 'easy' ? 'Easy' : 'Hard'}` : ''}`;
        slot('top-panel').hidden = true;
        slot('play').classList.remove('is-split');

        if (pins) {
            setOut();
        } else if (skyline) {
            sky ??= SkylineRound.create(slot('skyline').querySelector('[data-sky-round]'), { outlines: skylineOutlines });
            shown = sky.show(view.skyline);
        } else if (caseFile) {
            caseScreen ??= CaseScreen.create(slot('case').querySelector('[data-case-screen]'), { outlines: caseOutlines });
            caseScreen.show({ suspects: view.suspects, clues: view.clues, worth: view.worth });
            caseScreen.hud({ label: `Round ${view.round} of ${view.rounds}`, time: view.seconds, score: null, extra: `0 of ${view.players} accused` });
            if (view.clues?.rap) {
                caseScreen.look('rap');
            }
        } else {
            dealCards();
        }
    };

    // DistrictGuessr's district, and the map, cleared and framed: on Easy, zoomed into the district's state, as on the
    // phones.
    const setOut = () => {
        const round = view.round;
        const region = view.district.region;
        const easy = view.difficulty === 'easy';
        slot('district').textContent = view.district.name;
        slot('region').textContent = view.district.place;

        drawing ??= GuessrMap.draw(slot('map'), outlines);
        placed = drawing.then((drawn) => {
            if (dealt !== round) {
                return;
            }

            marks.forEach((layer) => layer.remove());
            marks = [];
            drawn.map.invalidateSize();
            GuessrMap.frame(drawn, region, easy, { padding: [16, 16] });
        });
    };

    // Higher or Lower's two cards, the second's count a question mark.
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
        if (caseFile) {
            caseScreen?.hud({ time: Math.ceil(left / 1000) });
            caseScreen?.clock(left / 1000, view.seconds);
        }
        host.classList.toggle('is-hurrying', left > 0 && left <= 3000);
        slot('note').textContent = standing()
            ? `${view.answered} of ${view.left} still in answered`
            : `${view.answered} of ${view.players} ${pins ? 'pinned' : caseFile ? 'accused' : 'answered'}`;
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

    // A leaderboard's rows; in Last One Standing, each with whether they're still in, or the round they went out in.
    const board = (list, rows) => {
        list.replaceChildren(...rows.map((row) => {
            const item = document.createElement('li');
            item.className = `live-board-row${row.rank <= 3 ? ` is-top-${row.rank}` : ''}${'out' in row && row.out !== null ? ' is-out' : ''}`;
            const tag = !('out' in row) ? null : row.out === null ? 'Still in' : row.out === 0 ? 'Watching' : `Out in round ${row.out}`;

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
    };

    const standing = () => view?.mode === 'last-one-standing';

    // Higher or Lower's answer: the count that was hidden, and how many said which; in Last One Standing, who went out,
    // or that everyone still in got it wrong and stays in, and how many are left.
    const showCount = (state) => {
        const next = slot('cards').querySelector('.game-card-next');
        next.classList.add('is-right');
        countUp(next.querySelector('[data-card="crimes"]'), state.answer);

        if (!standing()) {
            slot('note').textContent = `It was ${state.right}! ${state.said.higher} said higher, ${state.said.lower} said lower.`;
            return;
        }

        const out = state.knocked ?? [];
        const names = out.slice(0, 6).join(', ') + (out.length > 6 ? ` and ${out.length - 6} more` : '');
        const what = state.saved
            ? 'Everyone still in got it wrong, so they all stay in.'
            : (out.length ? `Out: ${names}.` : 'No one out.');
        slot('note').textContent = `It was ${state.right}! ${what} ${state.left} still in.`;
    };

    // DistrictGuessr's answer: where the district is, and everyone's pins, closest first, the 3 closest in gold, silver
    // and bronze with their names and how far off they were. Framed on the district and those 3; the map beside the
    // top 5.
    const showPins = (state) => {
        const closest = state.pins[0];
        slot('note').textContent = closest
            ? `It's here! Closest: ${closest.name}, ${GuessrMap.km(closest.km)} km away.`
            : "It's here. No one pinned it.";
        slot('play').classList.toggle('is-split', state.leaderboard.length > 0);

        placed = placed.then(() => drawing).then(({ map }) => {
            const answer = L.latLng(state.answer.lat, state.answer.lng);
            const framed = [answer];

            // The farthest first, so the closest are drawn on top.
            [...state.pins].reverse().forEach((pin, index) => {
                const place = state.pins.length - index;
                const at = L.latLng(pin.lat, pin.lng);
                const dot = L.circleMarker(at, {
                    radius: place <= 3 ? 9 : 6,
                    color: '#0e1013',
                    weight: 2,
                    fillColor: ['#f5c26b', '#d3dae1', '#e0a77a'][place - 1] ?? '#3aaaf2',
                    fillOpacity: 0.95,
                    interactive: false,
                }).addTo(map);

                if (place <= 3) {
                    // To the right of the dot, or on the map's right half, to its left.
                    dot.bindTooltip(`${place}. ${pin.name} · ${GuessrMap.km(pin.km)} km`, {
                        permanent: true, direction: 'auto', className: 'live-host-tip',
                    });
                    framed.push(at);
                }

                marks.push(dot);
            });

            marks.push(L.marker(answer, { icon: GuessrMap.pin('answer'), keyboard: false, interactive: false }).addTo(map));
            map.invalidateSize();
            map.fitBounds(L.latLngBounds(framed), { padding: [60, 60], maxZoom: 8 });
        });
    };

    // Skyline's answer: everyone's guesses as rings round the tower to guess, rising into place, then the real tower
    // growing through them, the 3 closest named; the towers beside the top 5.
    const showHeights = (state) => {
        const closest = state.guesses[0];
        const was = number.format(state.answer);
        slot('note').textContent = closest
            ? `It was ${was}! Closest: ${closest.name}, with ${number.format(closest.value)}.`
            : `It was ${was}. No one guessed.`;
        slot('play').classList.toggle('is-split', state.leaderboard.length > 0);

        const round = state.round;
        shown = shown.then(() => (dealt === round ? sky.reveal({ crimes: state.answer, ghosts: state.guesses }) : null));
    };

    // Case File's answer: every clue open, the stamp, the district marked, how many picked each suspect, how many got it
    // and the quickest who did; the desk beside the top 5.
    const showCase = (state) => {
        const district = state.suspects[state.answer]?.name ?? '';
        const fastest = state.fastest ? ` Fastest: ${state.fastest.name}, ${Number(state.fastest.seconds).toFixed(1)} s.` : '';
        slot('note').textContent = state.solved > 0
            ? `It was ${district}! ${state.solved} of ${state.players} solved it.${fastest}`
            : `It was ${district}. No one solved it.`;
        slot('play').classList.toggle('is-split', state.leaderboard.length > 0);
        caseScreen?.reveal({ culprit: state.answer, clues: state.clues, picks: state.picks, text: slot('note').textContent });
        caseScreen?.hud({ extra: '' });
    };

    // The round's answer, once, and the top 5.
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
        board(slot('top'), state.leaderboard);
        slot('top-panel').hidden = state.leaderboard.length === 0;

        if (pins) {
            showPins(state);
        } else if (skyline) {
            showHeights(state);
        } else if (caseFile) {
            showCase(state);
        } else {
            showCount(state);
        }
    };

    // The end: the top 3 on the podium, second, first and third, and everyone under it.
    const finish = (state) => {
        clearTimeout(checking);

        if (!part('finished').hidden) {
            return;
        }

        show('finished');

        // Last One Standing's winner, by name, when one is left.
        const left = standing() ? state.leaderboard.filter((row) => row.out === null) : [];
        if (left.length === 1) {
            document.getElementById('host-end-heading').textContent = `Last one standing: ${left[0].name}!`;
        }

        const [first, second, third] = state.leaderboard;
        slot('podium').replaceChildren(...[second, first, third].filter(Boolean).map((row) => {
            const step = document.createElement('li');
            step.className = `live-podium-step is-top-${row.rank}`;

            for (const [kind, text] of [['name', row.name], ['score', `${number.format(row.score)} ${row.score === 1 ? 'point' : 'points'}`], ['rank', row.rank]]) {
                const cell = document.createElement('span');
                cell.className = `live-podium-${kind}`;
                cell.textContent = text;
                step.append(cell);
            }

            return step;
        }));

        // DistrictGuessr's awards, under the podium.
        const awards = window.GuessrMap ? GuessrMap.awards(state.awards ?? []) : [];
        slot('awards').replaceChildren(...awards);
        slot('awards-panel').hidden = awards.length === 0;

        board(slot('board'), state.leaderboard);
    };

    host.querySelectorAll('[data-host-action]').forEach((button) => {
        button.addEventListener('click', () => act(button.dataset.hostAction));
    });

    setInterval(tick, 100);
    check();
})();
