// Case File's game screen (_case-screen.blade.php), for the solo game (case-file.js), the phones (live.js) and the big
// screen (live-host.js), played as an adventure game is, all in one pixel screen: the office, its clues to tap and
// look at close up, the corkboard's suspects to pick and accuse, a strip along the top with the case, the time, what
// it's worth and the score, the clock on the wall counting down, and a text box along the foot that types out what's
// said, with the choices. The office keeps its pixels square at any size: all of it on a wide screen, on a phone its
// middle, as tall as it goes. On the big screen ('host') nothing takes taps, and each clue's close-up shows as it opens.
window.CaseScreen = (() => {
    const number = new Intl.NumberFormat('en-MY');
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    // How long a clue takes to turn over (case-turn, in case-file.css), and a little more: then its close-up.
    const TURN = 400;
    const names = () => ({ rap: t('Rap sheet'), trend: t('Trend report'), rank: t('Ranking'), photo: t('Photo'), tip: t('Tip-off') });

    // What each clue says, in words, from what the server found.
    const says = {
        rap: (s) => t(':count crimes in :year. Most common: :type, :most.', { count: number.format(s.crimes), year: s.year, type: s.type, most: number.format(s.count) }),
        trend: (s) => {
            const year = s.change === null
                ? t('No figures for :from to compare with.', { from: s.from })
                : s.change > 0 ? t('Up :change% from :from to :to.', { change: s.change, from: s.from, to: s.to })
                    : s.change < 0 ? t('Down :change% from :from to :to.', { change: -s.change, from: s.from, to: s.to })
                        : t('No change from :from to :to.', { from: s.from, to: s.to });
            const since = s.long === null ? ''
                : s.long > 0 ? t('Up :change% since :since.', { change: s.long, since: s.since })
                    : s.long < 0 ? t('Down :change% since :since.', { change: -s.long, since: s.since })
                        : t('The same as in :since.', { since: s.since });
            return `${year} ${since}`.trim();
        },
        rank: (s) => t('No. :rank of :of police districts in its state, by crime in :year.', { rank: s.rank, of: s.of, year: s.year }),
        photo: () => t('Taken somewhere in the red blur, in its state.'),
        tip: (s) => t('An informant called: "Its name starts with :letter."', { letter: s.letter }),
    };

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

    // A line pixel by pixel (Bresenham), on a canvas's 2D context.
    const line = (paint, x0, y0, x1, y1) => {
        const dx = Math.abs(x1 - x0);
        const dy = -Math.abs(y1 - y0);
        const sx = x0 < x1 ? 1 : -1;
        const sy = y0 < y1 ? 1 : -1;
        let err = dx + dy;
        for (;;) {
            paint.fillRect(x0, y0, 1, 1);
            if (x0 === x1 && y0 === y1) {
                return;
            }
            const e2 = 2 * err;
            if (e2 >= dy) {
                err += dy;
                x0 += sx;
            }
            if (e2 <= dx) {
                err += dx;
                y0 += sy;
            }
        }
    };

    // The photo's picture: the state's outline in sepia, and the blur, as an old photo.
    const picture = (canvas, outlines, s) => {
        const paint = canvas.getContext('2d');
        const { width, height } = canvas;
        const features = (outlines?.features ?? []).filter((feature) => s.regions.includes(feature.properties?.shapeISO));
        const rings = features.flatMap((feature) => (feature.geometry.type === 'Polygon' ? [feature.geometry.coordinates]
            : feature.geometry.type === 'MultiPolygon' ? feature.geometry.coordinates : [])).map((polygon) => polygon[0]);

        paint.fillStyle = '#2b2119';
        paint.fillRect(0, 0, width, height);

        const points = rings.flat().concat([[s.lng, s.lat]]);
        const lngs = points.map((point) => point[0]);
        const lats = points.map((point) => point[1]);
        const middle = [(Math.min(...lngs) + Math.max(...lngs)) / 2, (Math.min(...lats) + Math.max(...lats)) / 2];
        const squeeze = Math.cos((middle[1] * Math.PI) / 180);
        const across = (Math.max(...lngs) - Math.min(...lngs)) * squeeze || 0.1;
        const down = (Math.max(...lats) - Math.min(...lats)) || 0.1;
        const scale = Math.min((width * 0.86) / across, (height * 0.86) / down);
        const place = ([lng, lat]) => [width / 2 + (lng - middle[0]) * squeeze * scale, height / 2 - (lat - middle[1]) * scale];

        paint.fillStyle = '#b89a6a';
        paint.strokeStyle = '#e6d3a8';
        paint.lineWidth = Math.max(1, width / 96);
        for (const ring of rings) {
            paint.beginPath();
            ring.forEach((point, index) => paint[index ? 'lineTo' : 'moveTo'](...place(point)));
            paint.closePath();
            paint.fill();
            paint.stroke();
        }

        // The blur: the km round about it, on the photo's scale (111 km a degree of latitude).
        const [x, y] = place([s.lng, s.lat]);
        const radius = Math.max(2, (s.km / 111.32) * scale);
        const blur = paint.createRadialGradient(x, y, 0, x, y, radius);
        blur.addColorStop(0, 'rgba(214, 64, 58, .85)');
        blur.addColorStop(0.6, 'rgba(214, 64, 58, .45)');
        blur.addColorStop(1, 'rgba(214, 64, 58, 0)');
        paint.fillStyle = blur;
        paint.fillRect(0, 0, width, height);
        canvas.hidden = false;
    };

    // The trend report's chart, in pixels: the first year's crime, the year before's and the latest's, from what they
    // went up or down by; red going up, green going down.
    const chart = (canvas, s) => {
        const paint = canvas.getContext('2d');
        const { width, height } = canvas;
        paint.fillStyle = '#ece4ce';
        paint.fillRect(0, 0, width, height);
        paint.fillStyle = '#d8cdb2';
        for (let y = 3; y < height; y += 6) {
            paint.fillRect(0, y, width, 1);
        }
        const values = [s.long === null ? null : 100 / (1 + s.long / 100), s.change === null ? null : 100 / (1 + s.change / 100), 100];
        const known = values.filter((value) => value !== null);
        const low = Math.min(...known);
        const span = Math.max(...known) - low || 1;
        const xs = [5, Math.round(width / 2), width - 6];
        const points = values.map((value, index) => (value === null ? null : [xs[index], Math.round(height - 6 - ((value - low) / span) * (height - 13))]));

        paint.fillStyle = (s.change ?? s.long ?? 0) > 0 ? '#c43c3c' : '#3f8f5a';
        for (let index = 0; index < points.length - 1; index++) {
            if (points[index] && points[index + 1]) {
                line(paint, ...points[index], ...points[index + 1]);
                line(paint, points[index][0], points[index][1] + 1, points[index + 1][0], points[index + 1][1] + 1);
            }
        }
        paint.fillStyle = '#1b1d23';
        points.filter(Boolean).forEach(([x, y]) => paint.fillRect(x - 1, y - 1, 3, 3));
    };

    // Each clue close up, from what it says.
    const close = {
        rap: (s, { round, mugshot }) => {
            const paper = make('article', 'case-paper is-rap');
            paper.append(make('h3', 'case-paper-title', t('Rap sheet')));
            if (round) {
                paper.append(make('span', 'case-paper-mark', t('Case :case', { case: round })));
            }
            const body = make('div', 'case-rap');
            const shot = make('img', 'case-mugshot');
            shot.src = mugshot;
            shot.alt = '';
            shot.width = 28;
            shot.height = 32;
            const facts = make('dl', 'case-facts');
            for (const [term, value, icon] of [[t('Crimes in :year', { year: s.year }), number.format(s.crimes)], [t('Most common'), `${s.type}, ${number.format(s.count)}`, s.icon]]) {
                const row = make('div');
                const dd = make('dd');
                if (icon) {
                    const glyph = make('span', 'material-icon', icon);
                    glyph.setAttribute('aria-hidden', 'true');
                    dd.append(glyph);
                }
                dd.append(value);
                row.append(make('dt', '', term), dd);
                facts.append(row);
            }
            body.append(shot, facts);
            paper.append(body);
            return paper;
        },
        trend: (s) => {
            const paper = make('article', 'case-paper is-trend');
            paper.append(make('h3', 'case-paper-title', t('Trend report')));
            const canvas = make('canvas', 'case-chart');
            canvas.width = 72;
            canvas.height = 40;
            chart(canvas, s);
            const years = make('p', 'case-chart-years');
            years.append(make('span', '', s.long === null ? '' : s.since), make('span', '', s.change === null ? '' : s.from), make('span', '', s.to));
            paper.append(canvas, years, make('p', 'case-paper-text', says.trend(s)));
            return paper;
        },
        rank: (s) => {
            const paper = make('article', 'case-paper is-ledger');
            paper.append(make('h3', 'case-paper-title', t('Ranking')));
            const rows = make('ol', 'case-ledger');
            for (let place = 1; place <= s.of; place++) {
                const row = make('li', place === s.rank ? 'is-it' : '');
                row.append(make('span', 'case-ledger-place', `${place}.`), make('span', 'case-ledger-name', place === s.rank ? '?' : ''));
                rows.append(row);
            }
            paper.append(rows, make('p', 'case-paper-text', says.rank(s)));
            return paper;
        },
        photo: (s, { outlines }) => {
            const frame = make('figure', 'case-polaroid');
            const canvas = make('canvas', 'case-polaroid-picture');
            canvas.width = 64;
            canvas.height = 48;
            Promise.resolve(outlines).then((states) => picture(canvas, states, s));
            frame.append(canvas, make('figcaption', '', says.photo(s)));
            return frame;
        },
        tip: (s, { typed, phone: picture }) => {
            const call = make('div', 'case-call');
            const phone = make('img', 'case-call-phone');
            phone.src = picture;
            phone.alt = '';
            phone.width = 72;
            phone.height = 48;
            const bubble = make('p', 'case-bubble');
            typed(bubble, says.tip(s));
            call.append(phone, bubble);
            return call;
        },
    };

    // The screen in the element given ([data-case-screen]): the states' outlines (a promise of the boundaries file),
    // what to do when a closed clue is to be opened (solo, returning once it is) and when a suspect is accused.
    const create = (root, { outlines, onOpen = async () => {}, onAccuse = () => {} } = {}) => {
        const mode = root.dataset.mode;
        const host = mode === 'host';
        const slot = (name) => root.querySelector(`[data-cs="${name}"]`);
        const views = [...root.querySelectorAll('[data-cs-view]')];
        const costs = JSON.parse(root.dataset.caseCosts ?? '{}');
        let current = null;
        let suspects = [];
        let opened = {};
        let chosen = null;
        let locked = false;
        let revealed = false;
        let round = null;
        let typing = null;
        // What's said at the answer, with its choices: said again, whole, on coming back to the suspects.
        let answered = null;

        // The office the size of the screen, its pixels square: on a wide screen all of it across, 180 of its rows from
        // under the fan, the strip along the top over the ceiling; on a narrow one its middle across, from the top, the
        // rest of the screen floor. --px is that scale rounded, for the close-ups' wood and cork.
        const fit = () => {
            const width = root.clientWidth;
            const height = root.clientHeight;
            if (!width || !height) {
                return;
            }
            const wide = width / height >= 1.4;
            const [from, across] = wide ? [0, 320] : [24, 272];
            const k = width / across;
            // On a narrow screen the strip along the top can take two lines: the wall starts under it.
            const strip = slot('hud').hidden ? 0 : slot('hud').offsetHeight;
            const y = wide ? -18 * k + Math.max(0, (height - 180 * k) / 2) : Math.max(0, strip - 30 * k);
            slot('scene').style.transform = `translate(${-from * k}px, ${y}px) scale(${k})`;
            root.style.setProperty('--px', String(Math.max(2, Math.round(k))));
        };
        const resized = new ResizeObserver(fit);
        resized.observe(root);
        resized.observe(slot('hud'));

        // A one-off movement over, or cut off by its view going: its class off, so it isn't played again.
        const settled = ({ target, animationName }) => {
            const playing = { 'case-turn': 'is-opening', 'case-develop': 'is-developing', 'case-stamp': 'is-stamping' }[animationName];
            if (playing) {
                target.classList.remove(playing);
            }
        };
        root.addEventListener('animationend', settled);
        root.addEventListener('animationcancel', settled);

        const nav = () => {
            const button = slot('nav');
            if (!button) {
                return;
            }
            button.hidden = !['room', 'clue', 'board'].includes(current);
            slot('nav-icon').textContent = current === 'room' ? 'groups' : 'arrow_back';
            slot('nav-label').textContent = current === 'room' ? t('Suspects') : t('Desk');
        };

        const view = (name) => {
            current = name;
            views.forEach((section) => {
                section.hidden = section.dataset.csView !== name;
                // A one-off movement (a clue turning over, the photo developing, a stamp coming down) isn't played
                // again on coming back: a view shown again would start it over.
                if (section.hidden) {
                    section.querySelectorAll('.is-opening, .is-developing, .is-stamping').forEach((element) => element.classList.remove('is-opening', 'is-developing', 'is-stamping'));
                }
            });
            root.dataset.view = name;
            slot('hud').hidden = !['room', 'clue', 'board'].includes(name);
            nav();
            fit();
            if (name === 'board' && revealed && answered) {
                say(answered.text, answered.choices, { whole: true });
            }
        };

        // Words typed out a few letters at a time into the element given, unless motion is turned down; all at once on
        // a tap. All of them are laid out from the start, those still to come unseen, so the lines don't change as they
        // type: no word jumps to the next line, and the box doesn't grow. Screen readers get them whole.
        const typed = (element, text) => {
            element.onclick = null;
            if (calm) {
                element.textContent = text;
                return null;
            }
            const whole = make('span', 'visually-hidden', text);
            const said = make('span');
            const unsaid = make('span', 'case-unsaid');
            said.setAttribute('aria-hidden', 'true');
            unsaid.setAttribute('aria-hidden', 'true');
            element.replaceChildren(whole, said, unsaid);
            let count = 0;
            let step = null;
            const finish = () => {
                clearInterval(step);
                element.onclick = null;
                element.textContent = text;
            };
            const show = () => {
                said.textContent = text.slice(0, count);
                unsaid.textContent = text.slice(count);
            };
            show();
            step = setInterval(() => {
                count += 2;
                if (count >= text.length) {
                    finish();
                } else {
                    show();
                }
            }, 24);
            element.onclick = finish;
            return step;
        };

        // The text box: what's said, typed out (whole, when said again; and whole to screen readers), and the choices,
        // each a button.
        const say = (text, choices = [], { whole = false } = {}) => {
            const box = slot('dialogue');
            box.hidden = false;
            root.classList.add('has-dialogue');
            clearInterval(typing);
            typing = null;
            if (whole) {
                slot('say').onclick = null;
                slot('say').textContent = text;
            } else {
                typing = typed(slot('say'), text);
            }
            slot('say-aloud').textContent = text;
            slot('choices').replaceChildren(...choices.map((choice) => {
                const button = make('button', `case-choice${choice.primary ? ' is-primary' : ''}`, choice.label);
                button.type = 'button';
                button.addEventListener('click', () => choice.action());
                return button;
            }));
            slot('choices').querySelector('.is-primary')?.focus({ preventScroll: true });
        };

        const hush = () => {
            clearInterval(typing);
            slot('dialogue').hidden = true;
            root.classList.remove('has-dialogue');
        };

        // A clue close up: its paper, photo or call, to read; and back to the desk from the strip along the top.
        const look = (clue) => {
            if (!opened[clue]) {
                return;
            }
            slot('close').replaceChildren(close[clue](opened[clue], { round, outlines, typed, mugshot: root.dataset.mugshot, phone: root.dataset.phone }));
            view('clue');
        };

        // A clue open: its object turned over on the desk, and the photo's picture drawn, developing once it's turned.
        // The turn is played only where it can be seen, the desk in view; then, if it's to be looked at, close up.
        const open = (clue, said, { looking = false, turning = true } = {}) => {
            const fresh = !opened[clue];
            opened[clue] = said;
            const seen = fresh && turning && !calm && current === 'room';
            root.querySelectorAll(`.case-prop[data-case-clue="${clue}"]`).forEach((prop) => {
                prop.classList.add('is-open');
                prop.classList.toggle('is-opening', seen);
            });
            if (clue === 'photo') {
                slot('photo').classList.toggle('is-developing', seen);
                Promise.resolve(outlines).then((states) => {
                    if (opened.photo === said) {
                        picture(slot('photo'), states, said);
                    }
                });
            }
            if (looking && seen) {
                setTimeout(() => {
                    if (current === 'room' && opened[clue] === said) {
                        look(clue);
                    }
                }, TURN);
            } else if (looking) {
                look(clue);
            }
            return fresh;
        };

        // A clue tapped on the desk: one open, close up; on the solo game, one closed, asked whether to open it at its
        // price; on a phone, it isn't open yet.
        const tap = (clue) => {
            if (host) {
                return;
            }
            if (opened[clue]) {
                hush();
                look(clue);
                return;
            }
            if (locked || revealed) {
                return;
            }
            if (mode === 'phone') {
                say(t('Not open yet: the clues open one by one.'), [{ label: t('OK'), primary: true, action: hush }]);
                return;
            }
            say(t('Open the :clue? It takes :points points off the case.', { clue: names()[clue].toLocaleLowerCase(), points: number.format(costs[clue] ?? 0) }), [
                { label: t('Open it'), primary: true, action: async () => { hush(); await onOpen(clue); } },
                { label: t('Not yet'), action: hush },
            ]);
        };

        const label = () => {
            const button = slot('accuse');
            if (!button) {
                return;
            }
            button.disabled = locked || chosen === null;
            slot('accuse-label').textContent = chosen === null ? t('Pick a suspect') : t('Accuse :name', { name: suspects[chosen].name });
        };

        const choose = (index) => {
            if (locked || host) {
                return;
            }
            chosen = index;
            slot('suspects').querySelectorAll('.case-card').forEach((card, at) => {
                card.classList.toggle('is-chosen', at === index);
                card.setAttribute('aria-pressed', String(at === index));
            });
            label();
        };

        // The suspects pinned up, each an index card with its number, name and state.
        const pin = (list) => {
            suspects = list;
            slot('suspects').replaceChildren(...list.map((suspect, index) => {
                const item = make('li');
                const card = make(host ? 'div' : 'button', 'case-card');
                if (!host) {
                    card.type = 'button';
                    card.setAttribute('aria-pressed', 'false');
                    card.addEventListener('click', () => choose(index));
                }
                card.append(make('span', 'case-card-number', index + 1), make('span', 'case-card-name', suspect.name), make('span', 'case-card-state', suspect.state));
                item.append(card);
                return item;
            }));
        };

        root.querySelectorAll('.case-prop').forEach((prop) => prop.addEventListener('click', () => tap(prop.dataset.caseClue)));
        slot('to-board').addEventListener('click', () => {
            hush();
            view('board');
        });
        slot('nav')?.addEventListener('click', () => {
            hush();
            view(current === 'room' ? 'board' : 'room');
        });
        slot('accuse')?.addEventListener('click', () => {
            if (chosen !== null && !locked) {
                onAccuse(chosen);
            }
        });

        return {
            view,
            say,
            hush,
            look,
            open,
            choose(index) {
                const was = locked;
                locked = false;
                choose(index);
                locked = was;
            },
            chosen: () => chosen,
            opened: (clue) => Boolean(opened[clue]),

            // A case: its suspects, none picked, its clues closed, then those open opened without their turning; the
            // office, and its number on the rap sheet.
            show({ suspects: list, clues = {}, worth = null }, number = null) {
                round = number;
                opened = {};
                chosen = null;
                locked = false;
                revealed = false;
                answered = null;
                root.classList.remove('is-locked');
                root.querySelectorAll('.case-prop').forEach((prop) => prop.classList.remove('is-open', 'is-opening'));
                slot('photo').hidden = true;
                slot('photo').classList.remove('is-developing');
                pin(list);
                label();
                if (slot('accuse')) {
                    slot('accuse').hidden = false;
                }
                Object.entries(clues).forEach(([clue, said]) => open(clue, said, { turning: false }));
                this.hud({ worth });
                hush();
                view('room');
            },

            // The clues given open, as a live game's open one by one: each new one turning over, and on a phone, said
            // with a way to look at it; on the big screen, close up.
            clues(map) {
                Object.entries(map ?? {}).forEach(([clue, said]) => {
                    // The rap sheet opens with the case, which says so.
                    if (open(clue, said, { looking: host && !revealed }) && mode === 'phone' && !revealed && current !== 'board' && clue !== 'rap') {
                        say(t('New clue: the :clue.', { clue: names()[clue].toLocaleLowerCase() }), [
                            { label: t('Look'), primary: true, action: () => { hush(); look(clue); } },
                            { label: t('Not now'), action: hush },
                        ]);
                    }
                });
            },

            // The strip along the top: any of the case's label, the seconds left, what it's worth (none hides it),
            // the score, and anything more.
            hud(fields) {
                if ('label' in fields) {
                    slot('label').textContent = fields.label;
                }
                if ('time' in fields) {
                    slot('time').textContent = fields.time;
                    slot('time-box').classList.toggle('is-hurrying', fields.time <= 10);
                }
                if ('worth' in fields) {
                    const box = slot('worth-box');
                    const was = slot('worth').textContent;
                    box.hidden = fields.worth === null || fields.worth === undefined;
                    slot('worth').textContent = number.format(fields.worth ?? 0);
                    if (!box.hidden && was && was !== slot('worth').textContent && !calm) {
                        box.classList.remove('is-dropping');
                        box.getBoundingClientRect();
                        box.classList.add('is-dropping');
                    }
                }
                if ('score' in fields) {
                    slot('score-box').hidden = fields.score === null;
                    slot('score').textContent = number.format(fields.score ?? 0);
                }
                if ('extra' in fields) {
                    slot('extra').hidden = !fields.extra;
                    slot('extra').textContent = fields.extra ?? '';
                }
            },

            // The clock on the wall: the time gone shaded in, and its hand, red for the last 10 seconds.
            clock(left, total) {
                const canvas = slot('clock');
                const paint = canvas.getContext('2d');
                const gone = Math.max(0, Math.min(1, 1 - left / total));
                paint.clearRect(0, 0, 17, 17);
                paint.fillStyle = 'rgba(214, 64, 58, .35)';
                for (let y = 0; y < 17; y++) {
                    for (let x = 0; x < 17; x++) {
                        const dx = x - 8;
                        const dy = y - 8;
                        const angle = (Math.atan2(dx, -dy) + 2 * Math.PI) % (2 * Math.PI);
                        if (dx * dx + dy * dy <= 30 && angle <= gone * 2 * Math.PI && gone > 0) {
                            paint.fillRect(x, y, 1, 1);
                        }
                    }
                }
                const angle = gone * 2 * Math.PI;
                paint.fillStyle = left <= 10 ? '#d6403a' : '#1b1d23';
                line(paint, 8, 8, Math.round(8 + 5 * Math.sin(angle)), Math.round(8 - 5 * Math.cos(angle)));
                paint.fillStyle = '#d6403a';
                paint.fillRect(8, 8, 1, 1);
            },

            // The seconds before the case, big over the office; none hides them.
            countdown(seconds) {
                slot('countdown').hidden = seconds === null;
                slot('countdown').textContent = seconds ?? '';
            },

            // No more opening clues or changing the pick.
            lock() {
                locked = true;
                root.classList.add('is-locked');
                slot('suspects').querySelectorAll('button.case-card').forEach((card) => { card.disabled = true; });
                label();
            },

            unlock() {
                locked = false;
                root.classList.remove('is-locked');
                slot('suspects').querySelectorAll('button.case-card').forEach((card) => { card.disabled = false; });
                label();
            },

            // The answer: every clue open, the suspects, GUILTY stamped on the district and WRONG on a wrong pick, how
            // many picked each on the big screen, and what's said, with its choices.
            reveal({ culprit, suspect = null, clues = {}, picks = null, text = '', choices = [] }) {
                this.lock();
                revealed = true;
                if (slot('accuse')) {
                    slot('accuse').hidden = true;
                }
                Object.entries(clues).forEach(([clue, said]) => open(clue, said, { turning: false }));
                slot('suspects').querySelectorAll('.case-card').forEach((card, index) => {
                    card.classList.toggle('is-culprit', index === culprit);
                    card.classList.toggle('is-wrong', index === suspect && suspect !== culprit);
                    card.querySelectorAll('.case-card-picks, .case-card-stamp').forEach((element) => element.remove());
                    if (index === culprit || (index === suspect && suspect !== culprit)) {
                        card.append(make('span', `case-card-stamp${calm ? '' : ' is-stamping'}`, index === culprit ? t('Guilty') : t('Wrong')));
                    }
                    if (picks) {
                        card.append(make('span', 'case-card-picks', tn(picks[index] ?? 0, ':count pick', ':count picks')));
                    }
                });
                this.hud({ worth: null });
                answered = null;
                view('board');
                if (text) {
                    answered = { text, choices };
                    say(text, choices);
                }
            },
        };
    };

    return { create, says };
})();
