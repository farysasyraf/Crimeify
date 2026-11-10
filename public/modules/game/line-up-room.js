// Line-Up's room (_line-up-room.blade.php, line-up.css), for the solo game (line-up.js), the phones (live.js) and the
// big screen (live-host.js): the suspects standing along the wall, each a silhouette (line-up-suspects.svg) holding a
// board with its letter, district and state; the state's outline in the corner, a letter where each is; and the floor,
// numbered. Tap one, then another, to swap them, or drag one along the line; on a keyboard, Space or Enter picks one
// up, the arrows move it, and Space, Enter or Escape puts it down, each move said to screen readers. At the answer, each
// rises on a riser to its count against the height chart, the right order's places stamped on the floor. On the big
// screen ('host') nothing takes taps, and at the answer they step into the right order first.
window.LineUpRoom = (() => {
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const svg = 'http://www.w3.org/2000/svg';
    const places = () => [t('1st'), t('2nd'), t('3rd'), t('4th'), t('5th')];

    const make = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    };

    // A drawing from the sprite on the page: a suspect's look, or a hand.
    const drawing = (symbol, className, box) => {
        const art = document.createElementNS(svg, 'svg');
        art.setAttribute('viewBox', box);
        art.setAttribute('class', className);
        art.setAttribute('aria-hidden', 'true');
        art.setAttribute('focusable', 'false');
        const use = document.createElementNS(svg, 'use');
        use.setAttribute('href', `#lu-${symbol}`);
        art.append(use);
        return art;
    };

    // The state's outline (its regions' shapes from the Map page's outlines) in the box given, and a projection to put
    // the suspects' dots on it.
    const outline = (states, regions, width, height) => {
        const features = (states?.features ?? []).filter((feature) => regions.includes(feature.properties?.shapeISO));
        const rings = features.flatMap((feature) => (feature.geometry.type === 'MultiPolygon' ? feature.geometry.coordinates.map((polygon) => polygon[0]) : [feature.geometry.coordinates[0]]));
        const points = rings.flat();
        if (points.length === 0) {
            return null;
        }
        const lat0 = points.reduce((sum, [, lat]) => sum + lat, 0) / points.length;
        const k = Math.cos((lat0 * Math.PI) / 180);
        const xs = points.map(([lng]) => lng * k);
        const ys = points.map(([, lat]) => -lat);
        const pad = 8;
        const scale = Math.min((width - 2 * pad) / (Math.max(...xs) - Math.min(...xs) || 1), (height - 2 * pad) / (Math.max(...ys) - Math.min(...ys) || 1));
        const dx = (width - (Math.max(...xs) - Math.min(...xs)) * scale) / 2 - Math.min(...xs) * scale;
        const dy = (height - (Math.max(...ys) - Math.min(...ys)) * scale) / 2 - Math.min(...ys) * scale;
        const project = (lng, lat) => [lng * k * scale + dx, -lat * scale + dy];
        // Every few points of each ring, enough at this size.
        const path = rings
            .filter((ring) => ring.length > 12)
            .map((ring) => `M${ring.filter((_, i) => i % 3 === 0).map(([lng, lat]) => project(lng, lat).map((n) => n.toFixed(1)).join(' ')).join('L')}Z`)
            .join('');
        return { path, project };
    };

    const create = (root, { outlines = null, onChange = () => {} } = {}) => {
        const host = root.dataset.mode === 'host';
        const slot = (name) => root.querySelector(`[data-lu="${name}"]`);
        const said = root.parentElement.querySelector('[data-lu="said"]');
        let face = null;
        let suspects = [];
        let order = [];
        let picked = null;
        let touched = false;
        let locked = false;
        let dragging = null;
        let skipClick = false;

        const slots = () => [...slot('line').children];
        const say = (text) => {
            if (said) {
                said.textContent = text;
            }
        };
        const nameOf = (index) => face.suspects[index].name;

        // Each suspect's words for screen readers: its letter, district and state, and where it stands.
        const label = () => {
            order.forEach((index, at) => {
                const suspect = face.suspects[index];
                suspects[index].setAttribute('aria-label', t(':letter: :name, :state. Place :place of :count.', {
                    letter: suspect.letter, name: suspect.name, state: face.state, place: at + 1, count: order.length,
                }));
            });
        };

        // The suspects into their places, those that moved gliding there (unless motion is turned down).
        const place = (from = null) => {
            const before = new Map(suspects.map((element) => [element, element.getBoundingClientRect()]));
            order.forEach((index, at) => slots()[at].prepend(suspects[index]));
            label();
            if (calm) {
                return;
            }
            suspects.forEach((element) => {
                const was = from?.get(element) ?? before.get(element);
                const now = element.getBoundingClientRect();
                const dx = was.left - now.left;
                const dy = was.top - now.top;
                if (Math.abs(dx) < 1 && Math.abs(dy) < 1) {
                    return;
                }
                element.style.transition = 'none';
                element.style.transform = `translate(${dx}px, ${dy}px)`;
                element.getBoundingClientRect();
                element.style.transition = '';
                element.style.transform = '';
            });
        };

        const pick = (index) => {
            picked = index;
            suspects.forEach((element, at) => {
                element.classList.toggle('is-picked', at === index);
                element.setAttribute('aria-pressed', String(at === index));
            });
            light(index);
        };

        const moved = () => {
            touched = true;
            onChange(order.slice());
        };

        const swap = (a, b) => {
            const at = order.indexOf(a);
            const to = order.indexOf(b);
            [order[at], order[to]] = [order[to], order[at]];
            place();
            moved();
            say(t(':first and :second swapped places.', { first: nameOf(a), second: nameOf(b) }));
        };

        // One to the place given, the others making room.
        const move = (index, to, from = null) => {
            const at = order.indexOf(index);
            if (at === to) {
                place(from);
                return;
            }
            order.splice(at, 1);
            order.splice(to, 0, index);
            place(from);
            moved();
            say(t(':name moved to place :place of :count.', { name: nameOf(index), place: to + 1, count: order.length }));
        };

        // Its dot on the map, lit while it's pointed at, focused or picked up.
        const light = (index) => {
            slot('map-art').querySelectorAll('[data-dot]').forEach((dot) => dot.classList.toggle('is-lit', Number(dot.dataset.dot) === index));
        };

        const tap = (index) => {
            if (locked || host || skipClick) {
                return;
            }
            if (picked === null) {
                pick(index);
                say(t(':name picked up. Tap where it goes, or use the arrow keys.', { name: nameOf(index) }));
            } else if (picked === index) {
                pick(null);
                say(t(':name put down.', { name: nameOf(index) }));
            } else {
                const first = picked;
                pick(null);
                swap(first, index);
            }
        };

        // Dragging along the line: once moved a few pixels, it follows the pointer, the place under it marked, and is
        // put there when let go.
        const nearest = (x) => {
            let best = 0;
            let gap = Infinity;
            slots().forEach((element, at) => {
                const box = element.getBoundingClientRect();
                const off = Math.abs(box.left + box.width / 2 - x);
                if (off < gap) {
                    gap = off;
                    best = at;
                }
            });
            return best;
        };

        const grab = (event, index) => {
            if (locked || host || event.button !== 0) {
                return;
            }
            dragging = { index, x: event.clientX, y: event.clientY, started: false, pointer: event.pointerId };
        };

        const drag = (event) => {
            if (!dragging || event.pointerId !== dragging.pointer) {
                return;
            }
            const element = suspects[dragging.index];
            const dx = event.clientX - dragging.x;
            if (!dragging.started) {
                if (Math.abs(dx) < 8) {
                    return;
                }
                dragging.started = true;
                element.setPointerCapture(event.pointerId);
                element.classList.add('is-dragging');
                pick(null);
                light(dragging.index);
            }
            element.style.transition = 'none';
            element.style.transform = `translate(${dx}px, -10px)`;
            const to = nearest(event.clientX);
            slots().forEach((cell, at) => cell.classList.toggle('is-target', at === to));
        };

        const drop = (event, cancelled = false) => {
            if (!dragging || event.pointerId !== dragging.pointer) {
                return;
            }
            const { index, started } = dragging;
            dragging = null;
            if (!started) {
                return;
            }
            const element = suspects[index];
            const from = new Map([[element, element.getBoundingClientRect()]]);
            element.classList.remove('is-dragging');
            element.style.transform = '';
            element.style.transition = '';
            slots().forEach((cell) => cell.classList.remove('is-target'));
            // A click follows letting go: not a tap.
            skipClick = true;
            setTimeout(() => { skipClick = false; }, 0);
            if (cancelled) {
                place(from);
                return;
            }
            move(index, nearest(event.clientX), from);
        };

        const keys = (event, index) => {
            if (locked || host) {
                return;
            }
            if (event.key === 'Escape' && picked !== null) {
                event.preventDefault();
                say(t(':name put down.', { name: nameOf(picked) }));
                pick(null);
                return;
            }
            if (picked !== index || !['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                return;
            }
            event.preventDefault();
            const to = order.indexOf(index) + (event.key === 'ArrowLeft' ? -1 : 1);
            if (to < 0 || to >= order.length) {
                return;
            }
            move(index, to);
            suspects[index].focus({ preventScroll: true });
        };

        // A suspect: its silhouette, its board with its letter, district and state, a hand each side, and the riser
        // it stands on at the answer, with its count.
        const suspect = (who, index) => {
            const element = make(host ? 'div' : 'button', 'lu-suspect');
            element.dataset.suspect = index;
            if (!host) {
                element.type = 'button';
                element.setAttribute('aria-pressed', 'false');
                element.addEventListener('click', () => tap(index));
                element.addEventListener('pointerdown', (event) => grab(event, index));
                element.addEventListener('pointermove', drag);
                element.addEventListener('pointerup', (event) => drop(event));
                element.addEventListener('pointercancel', (event) => drop(event, true));
                element.addEventListener('keydown', (event) => keys(event, index));
            }
            element.addEventListener('pointerenter', () => light(index));
            element.addEventListener('focus', () => light(index));
            element.addEventListener('pointerleave', () => light(picked));
            element.addEventListener('blur', () => light(picked));

            const figure = make('span', 'lu-figure');
            // A name with a long word in smaller letters, so it fits the board without breaking.
            const longest = Math.max(...who.name.split(/[\s-]+/).map((word) => word.length));
            const board = make('span', `lu-board${longest >= 12 ? ' is-longer' : longest >= 9 ? ' is-long' : ''}`);
            board.append(make('span', 'lu-letter', who.letter), make('span', 'lu-name', who.name), make('span', 'lu-state', face.state),
                drawing('hand', 'lu-hand is-left', '0 0 30 40'), drawing('hand', 'lu-hand is-right', '0 0 30 40'));
            figure.append(drawing(who.look, 'lu-art', '0 0 120 260'), board);
            const riser = make('span', 'lu-riser');
            riser.append(make('span', 'lu-count'));
            element.append(figure, riser);
            return element;
        };

        const map = () => {
            const art = slot('map-art');
            art.replaceChildren();
            Promise.resolve(outlines).then((states) => {
                if (!face) {
                    return;
                }
                const drawn = outline(states, face.regions, 200, 160);
                slot('map').hidden = drawn === null;
                if (!drawn) {
                    return;
                }
                const shape = document.createElementNS(svg, 'path');
                shape.setAttribute('d', drawn.path);
                shape.setAttribute('class', 'lu-map-state');
                art.append(shape);
                const put = [];
                // Near where it is, the first spot clear of the dots already put, so each letter can be read.
                const nudges = [[0, 0], ...[15, 22, 30].flatMap((r) => Array.from({ length: 8 }, (_, i) => [r * Math.cos((i * Math.PI) / 4), r * Math.sin((i * Math.PI) / 4)]))];
                face.suspects.forEach((who, index) => {
                    const [ox, oy] = drawn.project(who.lng, who.lat);
                    const clear = nudges.map(([dx, dy]) => [ox + dx, oy + dy])
                        .find(([x, y]) => x > 7 && x < 193 && y > 7 && y < 153 && put.every(([px, py]) => Math.hypot(x - px, y - py) >= 15));
                    const [x, y] = clear ?? [ox, oy];
                    put.push([x, y]);
                    const dot = document.createElementNS(svg, 'g');
                    dot.setAttribute('class', 'lu-dot');
                    dot.dataset.dot = index;
                    const circle = document.createElementNS(svg, 'circle');
                    circle.setAttribute('cx', x.toFixed(1));
                    circle.setAttribute('cy', y.toFixed(1));
                    circle.setAttribute('r', '7');
                    const letter = document.createElementNS(svg, 'text');
                    letter.setAttribute('x', x.toFixed(1));
                    letter.setAttribute('y', (y + 3.4).toFixed(1));
                    letter.textContent = who.letter;
                    dot.append(circle, letter);
                    art.append(dot);
                });
                light(picked);
            });
        };

        // The floor's numbers, one a place; at the answer, each place's stamp.
        const floor = () => {
            slot('floor').replaceChildren(...order.map((_, at) => {
                const cell = make('span', 'lu-place');
                cell.append(make('b', 'lu-place-number', at + 1));
                return cell;
            }));
        };

        return {
            // A round's suspects as they first stand, none picked up, nothing moved yet.
            show(next) {
                face = next;
                order = next.suspects.map((_, index) => index);
                picked = null;
                touched = false;
                locked = false;
                root.classList.remove('is-locked', 'is-revealed');
                slot('marks').replaceChildren();
                slot('line').replaceChildren(...order.map(() => make('li', 'lu-slot')));
                suspects = next.suspects.map((who, index) => suspect(who, index));
                order.forEach((index, at) => slots()[at].append(suspects[index]));
                if (!calm) {
                    suspects.forEach((element, at) => {
                        element.classList.add('is-entering');
                        element.style.animationDelay = `${at * 70}ms`;
                        element.addEventListener('animationend', () => element.classList.remove('is-entering'), { once: true });
                    });
                }
                label();
                floor();
                map();
                say('');
            },

            // An empty room, for the countdown before the first round.
            clear() {
                face = null;
                suspects = [];
                order = [];
                slot('line').replaceChildren();
                slot('floor').replaceChildren();
                slot('marks').replaceChildren();
                slot('map').hidden = true;
                root.classList.remove('is-locked', 'is-revealed');
            },

            countdown(seconds) {
                slot('countdown').hidden = seconds === null;
                slot('countdown').textContent = seconds ?? '';
            },

            // The order as it stands, left to right, by where each first stood.
            order: () => order.slice(),
            touched: () => touched,

            // An order already given, as after reloading.
            set(given) {
                if (!Array.isArray(given) || given.length !== order.length) {
                    return;
                }
                order = given.slice();
                place();
                touched = true;
            },

            lock() {
                locked = true;
                pick(null);
                root.classList.add('is-locked');
                suspects.forEach((element) => {
                    if (!host) {
                        element.disabled = true;
                    }
                });
            },

            unlock() {
                locked = false;
                root.classList.remove('is-locked');
                suspects.forEach((element) => {
                    if (!host) {
                        element.disabled = false;
                    }
                });
            },

            // The answer: each on a riser as tall as its count, the taller the more, against the height chart's marks;
            // on the floor, each place's stamp, its suspect's right place, and with an order given, a tick in its place
            // or how far off it was. Without one (none given, or the big screen), they step into the right order first;
            // on the big screen, with how many players had each one in its place (placed).
            reveal({ crimes, truth, given = null, placed = null, players = 0 }) {
                this.lock();
                if (given === null) {
                    order = truth.slice();
                    place();
                }
                root.classList.add('is-revealed');

                const most = Math.max(...crimes, 1);
                const wall = slot('line').clientHeight;
                const tallest = Math.max(...suspects.map((element) => element.querySelector('.lu-figure').offsetHeight));
                const rise = Math.max(60, wall - tallest - 26);
                const least = Math.min(34, rise * 0.25);
                const height = (count) => least + (count / most) * (rise - least);

                order.forEach((index, at) => {
                    const element = suspects[index];
                    const riser = element.querySelector('.lu-riser');
                    riser.style.setProperty('--rise', `${height(crimes[index]).toFixed(0)}px`);
                    riser.style.transitionDelay = calm ? '0s' : `${at * 90}ms`;
                    element.querySelector('.lu-count').textContent = number.format(crimes[index]);
                });

                // A few round numbers up the wall.
                const base = 10 ** Math.floor(Math.log10(Math.max(1, most / 3)));
                const step = [1, 2, 5, 10, 20, 50].map((m) => m * base).find((s) => most / s <= 4);
                const floorHeight = root.clientHeight - wall;
                slot('marks').replaceChildren(...Array.from({ length: Math.floor(most / step) }, (_, i) => {
                    const value = (i + 1) * step;
                    const mark = make('span', 'lu-mark');
                    mark.style.bottom = `${floorHeight + height(value)}px`;
                    mark.append(make('span', 'lu-mark-value', number.format(value)));
                    return mark;
                }));

                const names = places();
                [...slot('floor').children].forEach((cell, at) => {
                    const index = order[at];
                    const right = truth.indexOf(index);
                    const off = given === null ? null : Math.abs(right - at);
                    const kind = off === null ? 'is-plain' : off === 0 ? 'is-right' : off === 1 ? 'is-near' : 'is-wrong';
                    // Its right place, and a tick, or how far off it was, under it where there's no room beside.
                    const stamp = make('span', `lu-stamp ${kind}${calm ? '' : ' is-stamping'}`);
                    stamp.append(make('span', 'lu-stamp-place', `${names[right]}${off === 0 ? ' ✓' : ''}`));
                    if (off) {
                        stamp.append(make('span', 'lu-stamp-off', t(':off off', { off })));
                    }
                    stamp.style.animationDelay = calm ? '0s' : `${order.length * 90 + 400 + at * 80}ms`;
                    cell.querySelectorAll('.lu-stamp, .lu-placed').forEach((element) => element.remove());
                    cell.append(stamp);
                    if (placed) {
                        cell.append(make('span', 'lu-placed', `${placed[index]} of ${players} had it here`));
                    }
                });
            },
        };
    };

    return { create };
})();
