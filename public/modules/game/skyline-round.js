// A round of Skyline on a page (_skyline-round.blade.php), for the solo game (skyline.js), the phones (live.js) and the
// big screen (live-host.js): fills in the district to guess, its crime, year and state, and every district's count as a
// list; keeps the slider, the box and the − and + buttons in step, and the glass tower with them; and draws the towers
// with skyline-scene.js, loaded the first time it's needed. Where it can't be loaded or drawn (no import maps, no
// WebGL), the list opens instead, and the round plays the same.
window.SkylineRound = (() => {
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let module = null;

    // The scene's module, once.
    const load = () => {
        module ??= import('crimeify/skyline-scene').catch(() => null);
        return module;
    };

    // The round in the element given ([data-sky-round]), the states' outlines (a promise of the boundaries file), and
    // what to do when the guess changes and when Enter or Confirm is pressed.
    const create = (element, { outlines, onChange = () => {}, onConfirm = () => {} } = {}) => {
        const slot = (name) => element.querySelector(`[data-sky="${name}"]`);
        const slider = slot('slider');
        const box = slot('number');
        let face = null;
        let moved = false;
        let locked = false;
        let scene = null;
        let drawing = null;

        // The scene, once, or none where it can't be drawn.
        const draw = () => {
            drawing ??= load().then((loaded) => {
                if (!loaded || !loaded.supported()) {
                    return null;
                }

                try {
                    scene = new loaded.SkylineScene(slot('scene'), { outlines, calm });
                    return scene;
                } catch {
                    return null;
                }
            }).then((made) => {
                slot('stage').hidden = !made;
                slot('flat').hidden = Boolean(made);
                if (!made) {
                    slot('details').open = true;
                }
                return made;
            });

            return drawing;
        };

        // Every district by its count, the tallest first, the one to guess with a question mark until its answer.
        const list = (crimes = null) => {
            const rows = [...face.towers].sort((a, b) => (b.crimes ?? -1) - (a.crimes ?? -1) || a.name.localeCompare(b.name));
            slot('list').replaceChildren(...rows.map((tower) => {
                const item = document.createElement('li');
                const target = tower.crimes === null;
                item.className = target ? 'is-target' : '';

                for (const [kind, text] of [['name', tower.name], ['count', target ? (crimes === null ? '?' : number.format(crimes)) : number.format(tower.crimes)]]) {
                    const cell = document.createElement('span');
                    cell.className = `skyline-list-${kind}`;
                    cell.textContent = text;
                    item.append(cell);
                }

                return item;
            }));
        };

        // The guess as given, kept between 0 and the round's most, everywhere it shows.
        const set = (value, { quiet = false } = {}) => {
            const kept = Math.max(0, Math.min(face?.most ?? 0, Math.round(Number(value) || 0)));

            if (slider) {
                slider.value = kept;
                if (document.activeElement !== box) {
                    box.value = kept;
                }
                slot('value').textContent = number.format(kept);
                slot('confirm').disabled = locked || !moved;
            }

            scene?.guess(kept);

            if (!quiet) {
                onChange(kept);
            }

            return kept;
        };

        const move = (value) => {
            if (locked || !face) {
                return;
            }

            moved = true;
            set(value);
        };

        if (slider) {
            slider.addEventListener('input', () => move(slider.value));
            box.addEventListener('input', () => {
                if (box.value !== '') {
                    move(box.value);
                }
            });
            box.addEventListener('change', () => set(slider.value, { quiet: true }));
            box.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    move(box.value);
                    onConfirm();
                }
            });
            element.querySelectorAll('[data-sky-step]').forEach((button) => {
                button.addEventListener('click', () => {
                    const step = Math.max(1, Math.round((face?.most ?? 100) / 100));
                    move(Number(slider.value) + Number(button.dataset.skyStep) * step);
                });
            });
            slot('confirm').addEventListener('click', () => onConfirm());
        }

        return {
            // A round (Skyline::face()): its words, its list, the guess back at none, and its towers. Resolves once
            // they're drawn, or once it's known they can't be.
            async show(round) {
                face = round;
                moved = false;
                locked = false;
                slot('district').textContent = round.target;
                slot('icon').textContent = round.icon;
                slot('type').textContent = round.type;
                slot('year').textContent = round.year;
                slot('state').textContent = round.state;
                slot('scene').setAttribute('aria-label', t(":state's police districts as towers, each as tall as its count of :type in :year. The tallest is :district, with :count.", {
                    state: round.state, type: round.type, year: round.year, district: round.tallest.name, count: number.format(round.tallest.crimes),
                }));
                list();

                if (slider) {
                    slider.max = round.most;
                    box.max = round.most;
                    slider.disabled = box.disabled = false;
                    element.querySelectorAll('[data-sky-step]').forEach((button) => { button.disabled = false; });
                    slot('controls').hidden = false;
                }
                set(0, { quiet: true });

                // The glass tower at the guess, as it may have moved while the towers were being drawn.
                const made = await draw();
                if (face === round) {
                    await made?.show(round);
                    if (face === round && slider && moved) {
                        made?.guess(Number(slider.value));
                    }
                }
            },

            // The guess so far, or none if it hasn't been moved.
            value: () => (moved && slider ? Number(slider.value) : null),

            // A guess already made, as after reloading the page.
            set(value) {
                moved = true;
                set(value, { quiet: true });
            },

            // No more changing the guess.
            lock() {
                locked = true;

                if (slider) {
                    slider.disabled = box.disabled = true;
                    slot('confirm').disabled = true;
                    element.querySelectorAll('[data-sky-step]').forEach((button) => { button.disabled = true; });
                }
            },

            unlock() {
                locked = false;

                if (slider) {
                    slider.disabled = box.disabled = false;
                    slot('confirm').disabled = !moved;
                    element.querySelectorAll('[data-sky-step]').forEach((button) => { button.disabled = false; });
                }
            },

            // The controls out of the way, as once a phone's guess is in, or for one sitting out.
            hideControls(hidden = true) {
                if (slot('controls')) {
                    slot('controls').hidden = hidden;
                }
            },

            // The answer: its count in the list, and the real tower grown in the scene, with the guess (none for no
            // guess) and on the big screen, everyone's (ghosts). Resolves once it's grown.
            async reveal({ crimes, guess = null, ghosts = [] }) {
                this.lock();
                list(crimes);
                const made = await drawing;
                await made?.reveal({ crimes, guess, ghosts });
            },
        };
    };

    return { create, load };
})();
