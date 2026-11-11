// Map page: Malaysia's states and federal territories on a MapLibre GL JS map, from the boundaries file in
// #state-map's data-boundaries, over a dark street map drawn from vector tiles (data-style). Pointing at a region shows
// its name; clicking it, or its button in the list, selects it and shows its details. With data-crime, each police
// district also gets a pin whose popup gives its crime figures for the year chosen in the panel, and the panel gives
// the country's or the chosen region's totals. The regions are shaded by crime per 100,000 people that year, fading
// from one year's shades to the next as the timeline over the bottom of the map plays them, and can stand up in 3D by
// it. The pins can be bubbles instead, sized by a crime's count, growing and shrinking from year to year, and as the
// year changes, rings ripple out from districts where it rose and in to those where it fell. The map opens on the globe and flies in to Malaysia, tilts and turns with two fingers or the right mouse button,
// and shows buildings in 3D close up. MapLibre draws on the graphics card (WebGL); it's loaded as a module
// (data-maplibre) once this script runs. The street map comes from OpenFreeMap, so it needs the internet; the regions,
// pins and figures come from this app, so they show without it, on a plain dark map.
(async () => {
    const container = document.getElementById('state-map');
    const status = document.querySelector('[data-map-status]');

    if (!container) {
        return;
    }

    // A problem loading part of the page, and which part, so it goes once that part loads after all.
    const showStatus = (text, part = 'map') => {
        status.textContent = text;
        status.dataset.part = part;
        status.hidden = false;

        // And as an error toast, where the page has them (toast.js; not the public map).
        if (window.toast) {
            window.toast('error', text);
        } else {
            window.addEventListener('DOMContentLoaded', () => window.toast?.('error', text), { once: true });
        }
    };

    let maplibregl;
    try {
        maplibregl = await import(container.dataset.maplibre);
    } catch {
        showStatus(t("The map couldn't load. Reload the page to try again."));
        return;
    }

    const details = document.querySelector('[data-state-details]');
    const showAll = document.querySelector('[data-show-all]');
    const yearChoice = document.querySelector('[data-crime-year]');
    const choices = new Map([...document.querySelectorAll('[data-state]')].map((button) => [button.dataset.state, button]));
    const motion = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // The search over the map works with this script, so it shows once the script runs (set up further down).
    const search = document.querySelector('[data-map-search]');
    if (search) {
        search.hidden = false;
    }

    // The app's blues: regions lightly shaded in the soft blue, and the one selected filled with the bright blue the
    // list and the menu mark the current choice with, and outlined in it, glowing, so it stands out on the dark map.
    // With crime per 100,000 people, each region is shaded by its rate instead.
    const palette = {
        normal: '#62a9dc',
        selected: '#3aaaf2',
        outline: '#3d8fc9',
        // A shade for each band of crime per 100,000 people, from pale yellow for the least to deep red for the most,
        // toned down like the app's other colours, and a little see-through, so the streets show under them.
        shades: ['#f3e3a3', '#efbf78', '#e4925a', '#cc6349', '#a63a4a'],
        shadedOutline: 'rgba(14, 18, 24, 0.85)',
        // A region without a rate that year, like Labuan in a year by state, whose crimes are in Sabah's.
        unshaded: '#7d8794',
        // The ripples: orange where crime rose, teal where it fell, checked to stay apart with colour blindness and
        // on the dark map.
        rose: '#e36b3c',
        fell: '#2f9fb8',
        // The police districts the map's AI points out (map-ask.js): ringed and numbered in violet, apart from the pins'
        // blue, the shades' yellows and reds and the ripples' orange and teal, and bigger than a pin, so the shape
        // says it too.
        picked: '#b18cff',
        pickedFill: '#7c4ddb',
    };

    // Malaysia's two halves, Peninsular and Borneo, with room around them: [west, south], [east, north].
    const malaysia = [[99.6, 0.8], [119.3, 7.4]];

    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) {
            node.textContent = text;
        }
        if (className) {
            node.className = className;
        }
        return node;
    };

    // What the cards over the map cover, as padding to fit Malaysia, a region or a popup into the rest of it: the
    // search at the top and the column down the left (data-map-cover), each as far as it reaches in. Under the map, on
    // a narrow screen, or with the map enlarged, they cover none of it, but the search, which stays on it.
    // The timeline over the bottom of the map is in the map, so it covers it enlarged too.
    const covers = [...document.querySelectorAll('[data-map-cover]')];
    let timeline = null;

    const clearOfCards = () => {
        const clear = { top: 16, right: 16, bottom: 16, left: 16 };

        if (!container.closest('dialog')) {
            const box = container.getBoundingClientRect();
            for (const cover of covers) {
                const rect = cover.getBoundingClientRect();
                if (getComputedStyle(cover).position !== 'absolute' || rect.width === 0) {
                    continue;
                }
                const side = cover.dataset.mapCover;
                const reach = { top: rect.bottom - box.top, left: rect.right - box.left, right: box.right - rect.left }[side];
                clear[side] = Math.max(clear[side], reach + 16);
            }
        }

        return clear;
    };

    const uncovered = () => {
        const clear = clearOfCards();

        if (timeline?.parentElement === container) {
            clear.bottom = Math.max(clear.bottom, container.getBoundingClientRect().bottom - timeline.getBoundingClientRect().top + 16);
        }

        return clear;
    };

    // The buttons and credits in the map's own language: MapLibre's words for them, in the page's.
    const locale = {
        'NavigationControl.ZoomIn': t('Zoom in'),
        'NavigationControl.ZoomOut': t('Zoom out'),
        'NavigationControl.ResetBearing': t('Face north, flat'),
        'GeolocateControl.FindMyLocation': t('Show where I am'),
        'GeolocateControl.LocationNotAvailable': t("Your location isn't available just now. Try again in a moment."),
        'Popup.Close': t('Close'),
    };

    // A shared link or the palette opening a district or station goes straight there; otherwise, where motion is
    // welcome, the map starts on the globe, over Malaysia, and flies in once the states are drawn.
    const opening = container.dataset.open ? JSON.parse(container.dataset.open) : null;
    const flyIn = motion && !opening;

    // The street map's style, or if it can't load, a plain dark map, so the states and pins still show.
    let style;
    try {
        const response = await fetch(container.dataset.style);
        if (!response.ok) {
            throw new Error(response.statusText);
        }
        style = await response.json();
    } catch {
        style = {
            version: 8,
            glyphs: 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf',
            sources: {},
            layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#121820' } }],
        };
        showStatus(t("The street map couldn't load, so the map shows the states without it."));
    }

    let map;
    try {
        map = new maplibregl.Map({
            container,
            style,
            ...(flyIn ? { center: [109.5, 4], zoom: 1.6 } : { bounds: malaysia, fitBoundsOptions: { padding: uncovered() } }),
            minZoom: 1,
            maxZoom: 18,
            maxPitch: 70,
            attributionControl: false,
            locale,
        });
    } catch {
        // Without WebGL, as on some old browsers, or with it turned off.
        showStatus(t("This browser can't draw the map: it needs WebGL, which is missing or turned off."));
        return;
    }

    // Ready for the regions and pins once the street map's style is drawn.
    const ready = new Promise((resolve) => map.once('load', resolve));

    // The globe, as the map opens and when zoomed far out; flat again closer in.
    map.on('style.load', () => {
        try {
            map.setProjection({ type: 'globe' });
        } catch {
            // An older browser keeps the flat map.
        }
    });

    // The credits at the bottom right: the street map's, as its style gives them, and the figures' and the places'.
    const credits = new Map();
    const creditBox = element('div', undefined, 'maplibregl-ctrl map-credits');
    const drawCredits = () => {
        const fromStyle = Object.keys(map.getStyle()?.sources ?? {}).map((id) => map.getSource(id)?.attribution).filter(Boolean);
        creditBox.innerHTML = [...new Set(['<a href="https://maplibre.org">MapLibre</a>', ...fromStyle, ...credits.values()])].join(' | ');
        placeTimeline();
    };
    map.on('sourcedata', (event) => event.isSourceLoaded && event.sourceDataType === 'metadata' && drawCredits());

    // A credit on the map, linked to its source where it has one, in place of the one before it; none without text.
    const credit = (key, text, about) => {
        if (!text) {
            credits.delete(key);
        } else {
            const node = element(about ? 'a' : 'span', text);
            if (about) {
                node.href = about;
            }
            credits.set(key, node.outerHTML);
        }
        drawCredits();
    };

    // Bottom corners stack upwards, so the credits go first, under the buttons.
    map.addControl({ onAdd: () => creditBox, onRemove: () => creditBox.remove() }, 'bottom-right');
    map.addControl(new maplibregl.NavigationControl({ showCompass: true, visualizePitch: true }), 'bottom-right');

    // The timeline goes over the bottom of the map, right of the column of cards and above the map's credits, which can
    // take two lines. On a narrow screen, where the cards go under the map, it goes under the map too, before them, so it
    // doesn't hide most of a small map; enlarged, it's back over the map.
    const narrow = window.matchMedia('(max-width: 999.98px)');

    function placeTimeline() {
        if (!timeline) {
            return;
        }

        const under = narrow.matches && !container.closest('dialog');
        if (under && timeline.previousElementSibling !== container) {
            container.after(timeline);
        } else if (!under && timeline.parentElement !== container) {
            container.append(timeline);
        }

        timeline.classList.toggle('map-timeline-under', under);
        timeline.style.left = under ? '' : `${clearOfCards().left}px`;
        timeline.style.bottom = under ? '' : `${creditBox.offsetHeight + 10}px`;
    }
    narrow.addEventListener('change', placeTimeline);

    // The sidebar narrowing, or the window resizing, changes the map's size; MapLibre has to be told.
    new ResizeObserver(() => {
        map.resize();
        placeTimeline();
    }).observe(container);

    // Each region's corners, for zooming to it, from the boundaries as loaded, and the boundaries, for which state a
    // point is in ("Near you", below).
    const regionBounds = new Map();
    let boundaryData = null;
    let selected = null;
    let hovered = null;

    // One year's figures from MapController@crime, once they've loaded.
    let crime = null;

    // Every year's crime per 100,000 people (CrimeRates), which come with each year's figures; whether there are any,
    // which needs the population figures; what the map is shaded by; the year it's shaded for; and whether the regions
    // stand up in 3D by it.
    let rates = null;
    let shading = false;
    let measure = 'all';
    let shownYear = null;
    let raised = false;
    // The crime the pins are sized by, "all", a category or a crime type; null for plain pins.
    let sizing = null;
    // The lists for what the map is shaded by and what the pins show, once built, to set them from the map's AI.
    let measureChoice = null;
    let pinsChoice = null;
    // Once the first year's figures are shown, the map can be read and moved by the map's AI (crimeMap, below).
    let markReady = () => {};
    const figuresShown = new Promise((resolve) => { markReady = resolve; });

    const count = new Intl.NumberFormat('en-MY');
    const decimal = new Intl.NumberFormat('en-MY', { maximumFractionDigits: 1 });
    const lang = document.documentElement.lang;

    // A rate as it reads best: whole numbers from 100, one decimal under.
    const perPeople = (rate) => (rate >= 100 ? count.format(Math.round(rate)) : decimal.format(rate));

    // "3rd" in English, "ke-3" in Bahasa Melayu.
    const ordinals = new Intl.PluralRules('en', { type: 'ordinal' });
    const ordinal = (number) => (lang === 'ms' ? `ke-${number}` : `${number}${{ one: 'st', two: 'nd', few: 'rd' }[ordinals.select(number)] ?? 'th'}`);

    // A region's rate that year, by what the map is shaded by; null without one.
    const rateOf = (code, year = shownYear) => rates?.years?.[year]?.regions?.[code]?.rates?.[measure] ?? null;

    // The scale's limits (CrimeRates::limits) split the rates into bands, the same in every year, each with its shade;
    // with fewer than five bands, the shades are spread over them.
    const shadeAt = (band, limits) => {
        const last = palette.shades.length - 1;
        return palette.shades[limits.length ? Math.round((band * last) / limits.length) : Math.floor(last / 2)];
    };

    const shadeOf = (rate) => {
        const limits = rates.scale[measure] ?? [];
        return shadeAt(limits.filter((limit) => rate >= limit).length, limits);
    };

    // How the regions are drawn, as MapLibre expressions by each region's code: its colour and how see-through it is,
    // shaded by its rate, or without rates in the app's blues; lighter when pointed at. A change of colour fades.
    const byRegion = (value, otherwise) => {
        const pairs = [...choices.keys()].flatMap((code) => [code, value(code)]);
        return pairs.length ? ['match', ['get', 'shapeISO'], ...pairs, otherwise] : otherwise;
    };
    const pointedAt = ['boolean', ['feature-state', 'hover'], false];

    // A change animated over a time, eased, a frame at a time: MapLibre fades a paint property only when it's one
    // value for every feature, not a region's own shade or a pin's own size, so those are moved along here. At once
    // without motion, and a new change of the same thing takes over from where the last one got to.
    const frames = new Map();
    const animate = (name, milliseconds, step) => {
        cancelAnimationFrame(frames.get(name));
        if (!motion || !milliseconds) {
            step(1);
            return;
        }
        const start = performance.now();
        // A frame's time can be a moment before the start, so never less than none of the way.
        const tick = (now) => {
            const progress = Math.min(1, Math.max(0, (now - start) / milliseconds));
            step(1 - (1 - progress) ** 3);
            if (progress < 1) {
                frames.set(name, requestAnimationFrame(tick));
            }
        };
        frames.set(name, requestAnimationFrame(tick));
    };
    const rgb = (hex) => [1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16));
    const between = (from, to, part) => from + (to - from) * part;

    // Each region's colour and, in 3D, its height: its rate's shade, or without rates the app's blues; and its rate
    // as a height, the highest of any year 120 km tall, so a height means the same in every year, as a shade does.
    const regionTarget = (code) => {
        const rate = shading ? rateOf(code) : null;
        const colour = !shading ? (code === selected ? palette.selected : palette.normal) : rate === null ? palette.unshaded : shadeOf(rate);
        const highest = Math.max(1, ...Object.values(rates?.years ?? {}).flatMap((year) => Object.values(year.regions).map((region) => region.rates[measure] ?? 0)));
        return { colour: rgb(colour), height: ((rate ?? 0) / highest) * 120000 };
    };
    const regionNow = new Map();

    // Fainter as the map zooms in to a town, so its streets and buildings show through.
    const regionOpacity = () => {
        const base = byRegion((code) => {
            if (!shading) {
                return code === selected ? 0.5 : 0.16;
            }
            return rateOf(code) === null ? 0.1 : 0.7;
        }, 0.16);
        const lit = ['case', pointedAt, ['min', ['+', base, 0.15], 0.92], base];
        return ['interpolate', ['linear'], ['zoom'], 7, lit, 10, ['*', lit, 0.55], 13, ['*', lit, 0.25]];
    };

    // How a number compares with the year before, e.g. "Down 7% from 1,324 in 2022". Fewer crimes is the good news.
    const change = (now, before) => {
        if (before === null || before === undefined || crime.previousYear === null) {
            return null;
        }

        const year = crime.previousYear;

        if (now === before) {
            return element('span', t('Same as in :year', { year }), 'crime-change');
        }
        if (before === 0) {
            return element('span', t('Up from 0 in :year', { year }), 'crime-change crime-up');
        }

        const percent = Math.round((Math.abs(now - before) / before) * 100);
        const since = { size: percent === 0 ? t('under 1%') : `${percent}%`, count: count.format(before), year };

        return now > before
            ? element('span', t('Up :size from :count in :year', since), 'crime-change crime-up')
            : element('span', t('Down :size from :count in :year', since), 'crime-change crime-down');
    };

    // Each category's total for a place, with how it compares with the year before.
    const totals = (place) => {
        const list = element('dl', undefined, 'crime-totals');

        for (const [category, label] of Object.entries(crime.categories)) {
            const row = element('div');
            const value = element('dd');
            const difference = change(place.totals[category], place.previous?.[category]);

            value.append(element('strong', count.format(place.totals[category])));
            if (difference) {
                value.append(' ', difference);
            }
            row.append(element('dt', label), value);
            list.append(row);
        }

        return list;
    };

    // A police district's own public address, to send, under its name in its popup: WhatsApp and the like show it with an
    // image of the district's chart. None without one.
    const shareRow = (district) => {
        if (!district.share) {
            return null;
        }

        const icon = (name) => {
            const glyph = element('span', name, 'material-icon');
            glyph.setAttribute('aria-hidden', 'true');
            return glyph;
        };
        const share = element('div', undefined, 'crime-popup-share');

        const whatsapp = element('a', undefined, 'crime-share');
        const text = t(':name police district: crime each year', { name: district.name });
        whatsapp.href = `https://wa.me/?text=${encodeURIComponent(`${text} ${district.share}`)}`;
        whatsapp.target = '_blank';
        whatsapp.rel = 'noopener';
        whatsapp.append(icon('share'), t('Share on WhatsApp'));

        const copy = element('button', undefined, 'crime-share');
        const copyLabel = element('span', t('Copy link'));
        copy.type = 'button';
        copy.append(icon('link'), copyLabel);
        copy.addEventListener('click', () => {
            const copied = () => {
                copyLabel.textContent = t('Link copied');
                setTimeout(() => { copyLabel.textContent = t('Copy link'); }, 2000);
            };
            // Without the clipboard, as on a page that isn't https, the link to copy by hand.
            const byHand = () => window.prompt(t('Copy this link'), district.share);
            navigator.clipboard ? navigator.clipboard.writeText(district.share).then(copied, byHand) : byHand();
        });

        share.append(whatsapp, copy);

        // Its profile: how its crime has changed, which crimes are most common, and its stations.
        if (district.profile) {
            const profile = element('a', undefined, 'crime-share');
            profile.href = district.profile;
            profile.append(icon('travel_explore'), t('Area profile'));
            share.append(profile);
        }

        return share;
    };

    // A police district's popup: each category's total and how it compares with the year before, then the
    // number of each crime type in it.
    const popup = (district) => {
        const content = element('div', undefined, 'crime-popup');
        const region = choices.get(district.region)?.dataset.name ?? district.region;

        content.append(
            element('h3', district.name),
            element('p', t('Police district in :region · :year', { region, year: crime.year }), 'crime-popup-place'),
            ...[shareRow(district)].filter(Boolean),
        );

        for (const [category, types] of Object.entries(crime.types)) {
            const list = element('table', undefined, 'crime-types');
            const caption = element('caption');
            const heading = element('span', undefined, 'crime-types-heading');
            const difference = change(district.totals[category], district.previous?.[category]);

            heading.append(element('span', crime.categories[category]), element('strong', count.format(district.totals[category])));
            caption.append(heading);
            if (difference) {
                caption.append(difference);
            }
            list.append(caption);

            for (const [type, label] of Object.entries(types)) {
                const row = element('tr');
                const name = element('th', label);
                name.scope = 'row';
                row.append(name, element('td', count.format(district.types[category]?.[type] ?? 0)));
                list.append(row);
            }

            content.append(list);
        }

        return content;
    };

    // What the panel says about the chosen region, or all of Malaysia.
    const fill = (code) => {
        const button = choices.get(code);
        details.replaceChildren();

        const heading = element('h2', button ? button.dataset.name : 'Malaysia');
        heading.id = 'state-panel-heading';
        details.append(heading);

        if (!crime) {
            details.append(element('p', button ? `${button.dataset.kind} · ${code}` : t('Choose a state on the map or from the list.'), 'muted'));
            return;
        }

        const place = button ? crime.regions[code] : crime.national;

        // A hint for a region too, so the panel keeps its height and the map under it doesn't jump when one is chosen.
        const hint = crime.byState
            ? t(":year's figures are by state, so there are no police district pins. Choose an earlier year for them.", { year: crime.year })
            : t(button ? 'Click a pin for one of its police districts.' : 'Click a pin for a police district, or choose a state.');

        if (!place) {
            // By state, Labuan's figures are in Sabah's and Putrajaya's in Kuala Lumpur's. The same lines as with
            // figures, so the panel keeps its height.
            const under = button && crime.byState ? choices.get(crime.countedUnder?.[code]) : null;
            details.append(
                element('p', button ? `${button.dataset.kind} · ${crime.year}` : String(crime.year), 'muted'),
                element('p', under
                    ? t("Counted in :under's figures for :year. Choose :under for them.", { under: under.dataset.name, year: crime.year })
                    : t('No crime figures for :year.', { year: crime.year }), 'crime-missing'),
                element('p', hint, 'muted crime-hint'),
            );
            return;
        }

        // What the figures cover: a region's police districts, or for a year by state, the state and the regions
        // counted in it.
        const coverage = () => {
            if (!button) {
                return t(crime.byState ? 'All states · :year' : 'All police districts · :year', { year: crime.year });
            }
            if (crime.byState) {
                const includes = place.includes?.length ? [t('with :regions', { regions: place.includes.join(` ${t('and')} `) })] : [];
                return [button.dataset.kind, crime.year, ...includes].join(' · ');
            }
            return [button.dataset.kind, tn(place.districts, ':count police district', ':count police districts'), crime.year].join(' · ');
        };

        details.append(element('p', coverage(), 'muted'), totals(place), ...[perHundredThousand(code)].filter(Boolean), element('p', hint, 'muted crime-hint'));
    };

    // "4.11 million", or under a million, the number.
    const millions = new Intl.NumberFormat('en-MY', { maximumFractionDigits: 2 });
    const population = (people) => (people >= 1e6 ? t(':count million', { count: millions.format(people / 1e6) }) : count.format(people));

    // The chosen region's or the country's crime per 100,000 people that year, by what the map is shaded by, how it
    // ranks among the regions, and the people it's divided by. Nothing without the population figures.
    const perHundredThousand = (code) => {
        const year = rates?.years?.[crime.year];
        const place = code ? year?.regions?.[code] : year?.national;

        if (!shading || !place) {
            return null;
        }

        const rate = place.rates[measure];
        const value = element('p', undefined, 'crime-rate-value');
        value.append(element('strong', perPeople(rate)), ` ${t('per 100,000 people')}`);

        const about = [rates.measures[measure]];
        if (code) {
            // Equal rates share a place.
            const ranked = Object.values(year.regions).map((region) => region.rates[measure]).sort((a, b) => b - a);
            const rank = ranked.indexOf(rate) + 1;
            about.push(rank === 1 ? t('Highest of :count', { count: ranked.length })
                : rank === ranked.length ? t('Lowest of :count', { count: ranked.length })
                    : t(':ordinal highest of :count', { ordinal: ordinal(rank), count: ranked.length }));
        }

        const block = element('div', undefined, 'crime-rate');
        block.append(
            value,
            element('p', about.join(' · '), 'crime-rate-about'),
            element('p', t('Population :people in :year', { people: population(place.people), year: crime.year }), 'crime-rate-about'),
        );

        return block;
    };

    // The panel stays as tall as it has been at this width, so a region with less to say, like Labuan in a year by
    // state, doesn't move the map under it.
    let tallest = { width: 0, height: 0 };

    const describe = (code) => {
        details.style.minHeight = '';
        fill(code);

        if (details.offsetWidth !== tallest.width) {
            tallest = { width: details.offsetWidth, height: 0 };
        }
        tallest.height = Math.max(tallest.height, details.offsetHeight);
        details.style.minHeight = `${tallest.height}px`;
    };

    // Moving the map: animated where motion is welcome, at once where it isn't.
    const duration = (milliseconds) => (motion ? milliseconds : 0);

    // A maxZoom given as undefined would stand in for MapLibre's own, so it's left out without one.
    const frame = (bounds, maxZoom, milliseconds = 700) => map.fitBounds(bounds, {
        padding: uncovered(), ...(maxZoom ? { maxZoom } : {}), duration: duration(milliseconds), essential: true,
    });

    // The regions' layers, once drawn: their colours, how see-through they are, how tall in 3D, and the one selected.
    const regionsDrawn = () => Boolean(map.getLayer('region-fill'));

    // Each region fades from its shade and height to the new ones.
    const paintRegions = () => {
        if (!regionsDrawn()) {
            return;
        }
        map.setPaintProperty('region-fill', 'fill-opacity', regionOpacity());
        map.setPaintProperty('region-line', 'line-color', shading ? palette.shadedOutline : palette.outline);
        for (const id of ['region-selected', 'region-selected-glow']) {
            map.setFilter(id, ['==', ['get', 'shapeISO'], selected ?? '']);
        }

        const targets = new Map([...regionBounds.keys()].map((code) => [code, regionTarget(code)]));
        const starts = new Map([...targets].map(([code, target]) => [code, regionNow.get(code) ?? target]));
        animate('regions', 700, (part) => {
            for (const [code, target] of targets) {
                const from = starts.get(code);
                const now = { colour: from.colour.map((value, at) => between(value, target.colour[at], part)), height: between(from.height, target.height, part) };
                regionNow.set(code, now);
                map.setFeatureState({ source: 'regions', id: code }, { colour: `rgb(${now.colour.map(Math.round).join(',')})`, height: now.height });
            }
        });
    };

    const select = (code, { zoom = false } = {}) => {
        const before = selected;
        selected = code;

        if (before) {
            choices.get(before)?.setAttribute('aria-pressed', 'false');
        }

        const known = code ? regionBounds.get(code) : null;

        if (known) {
            choices.get(code)?.setAttribute('aria-pressed', 'true');
            if (zoom) {
                frame(known, 9);
            }
        } else if (zoom) {
            frame(malaysia);
        }

        paintRegions();
        showAll.hidden = !known;
        describe(known ? code : null);
    };

    // The enlarge button, over the zoom buttons: the map moves into a modal dialog that fills most of the window over
    // the dimmed page, without the cards over it, keeping its view, pins and popup, and moves back when made small
    // again with the button, Escape or a click on the dimmed page.
    const dialog = document.createElement('dialog');
    dialog.className = 'map-dialog';
    dialog.setAttribute('aria-label', container.getAttribute('aria-label'));
    document.body.append(dialog);

    // Holds the map's place on the page while it's enlarged, so the page behind doesn't jump.
    const placeholder = element('div', undefined, 'state-map');

    const toggle = element('button');
    toggle.type = 'button';
    toggle.append(element('span', '', 'material-icon'));
    toggle.firstChild.setAttribute('aria-hidden', 'true');

    const labelToggle = (enlarged) => {
        const text = t(enlarged ? 'Make the map smaller' : 'Enlarge the map');
        toggle.setAttribute('aria-label', text);
        toggle.title = text;
        toggle.firstChild.textContent = enlarged ? 'close_fullscreen' : 'open_in_full';
    };
    labelToggle(false);

    const putBack = () => {
        if (placeholder.isConnected) {
            placeholder.replaceWith(container);
        }
    };

    const enlarge = () => {
        // The dialog says it has closed a moment after it does, so the map may not be back yet.
        putBack();
        container.replaceWith(placeholder);
        dialog.append(container);
        dialog.showModal();
        labelToggle(true);
        toggle.focus();
        map.resize();
        placeTimeline();
    };

    dialog.addEventListener('close', () => {
        if (dialog.open) {
            return;
        }
        putBack();
        labelToggle(false);
        toggle.focus();
        map.resize();
        placeTimeline();
    });

    toggle.addEventListener('click', () => (dialog.open ? dialog.close() : enlarge()));
    const enlargeBar = element('div', undefined, 'maplibregl-ctrl maplibregl-ctrl-group map-enlarge');
    enlargeBar.append(toggle);
    map.addControl({ onAdd: () => enlargeBar, onRemove: () => enlargeBar.remove() }, 'bottom-right');

    // Only a click that starts and ends on the dimmed page counts, not a drag of the map let go outside it.
    let pressedOutside = false;
    dialog.addEventListener('pointerdown', (event) => { pressedOutside = event.target === dialog; });
    dialog.addEventListener('click', (event) => {
        if (pressedOutside && event.target === dialog) {
            dialog.close();
        }
    });

    // A police district's popup, while one is open, and its district; Escape closes it, and only then makes an
    // enlarged map small again.
    let pinPopup = null;
    let open = null;

    container.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && pinPopup) {
            event.stopPropagation();
            pinPopup.remove();
        }
    });
    dialog.addEventListener('cancel', (event) => {
        if (pinPopup && container.contains(document.activeElement)) {
            event.preventDefault();
            pinPopup.remove();
        }
    });

    // A popup that opened partly under the cards, or off the map, moved into the part they leave clear.
    const keepInView = (shown) => {
        const clear = uncovered();
        const box = container.getBoundingClientRect();
        const rect = shown.getBoundingClientRect();
        const [left, right, top, bottom] = [box.left + clear.left, box.right - clear.right, box.top + clear.top, box.bottom - clear.bottom];
        const dx = rect.left < left ? rect.left - left : rect.right > right ? Math.min(rect.right - right, rect.left - left) : 0;
        const dy = rect.top < top ? rect.top - top : rect.bottom > bottom ? Math.min(rect.bottom - bottom, rect.top - top) : 0;

        if (dx || dy) {
            map.panBy([dx, dy], { duration: duration(300) });
        }
    };

    // A region's name, and with rates, its crime per 100,000 people in the year shown, as it is when pointed at; or a
    // police district's name, over its pin.
    const tip = new maplibregl.Popup({ closeButton: false, closeOnClick: false, className: 'map-tip', offset: 14, maxWidth: '260px' });
    // The pin it's over, if it's over one, so its figure can follow the year.
    let tipPin = null;

    const regionTip = (code) => {
        const name = choices.get(code)?.dataset.name ?? code;
        const content = element('span', undefined, 'region-tip');
        const rate = shading ? rateOf(code) : null;
        content.append(element('strong', name));
        if (rate !== null) {
            content.append(element('span', t(':rate per 100,000 people', { rate: perPeople(rate) })));
        }
        return content;
    };

    // A police district's name over its pin, and as a bubble, its count of the crime it's sized by.
    const pinTip = (key) => {
        const district = districtsByKey.get(key);
        const content = element('span', undefined, 'region-tip');
        content.append(element('strong', district?.name ?? ''));
        if (district && bubbled()) {
            content.append(element('span', `${count.format(countOf(district, sizing) ?? 0)} · ${crimeName(sizing)} · ${crime.year}`));
        }
        return content;
    };

    const point = (code) => {
        if (hovered === code) {
            return;
        }
        if (hovered) {
            map.setFeatureState({ source: 'regions', id: hovered }, { hover: false });
        }
        hovered = code;
        if (code) {
            map.setFeatureState({ source: 'regions', id: code }, { hover: true });
        }
    };

    const regionLayers = ['region-fill', 'region-3d'];
    // The pins, or the bubbles, each with room round it to point at, however small.
    const bubbled = () => Boolean(sizing) && districtsByKey.size > 0;
    const pinTargets = () => (bubbled() ? ['bubble-hits'] : ['district-pins', 'district-clusters']).filter((id) => map.getLayer(id));

    map.on('mousemove', (event) => {
        if (!regionsDrawn()) {
            return;
        }
        const pin = map.queryRenderedFeatures(event.point, { layers: pinTargets() })[0];
        const region = pin ? null : map.queryRenderedFeatures(event.point, { layers: regionLayers.filter((id) => map.getLayoutProperty(id, 'visibility') !== 'none') })[0];
        point(region?.properties.shapeISO ?? null);
        map.getCanvas().style.cursor = pin || region ? 'pointer' : '';

        tipPin = pin && !pin.properties.cluster ? pin.properties.key : null;
        if (tipPin) {
            tip.setLngLat(pin.geometry.coordinates).setDOMContent(pinTip(tipPin)).addTo(map);
        } else if (region) {
            tip.setLngLat(event.lngLat).setDOMContent(regionTip(region.properties.shapeISO)).addTo(map);
        } else {
            tip.remove();
        }
    });
    map.getCanvas().addEventListener('mouseleave', () => {
        point(null);
        tip.remove();
    });

    map.on('click', (event) => {
        if (!regionsDrawn()) {
            return;
        }
        const pin = map.queryRenderedFeatures(event.point, { layers: pinTargets() })[0];
        if (pin) {
            return;
        }
        const region = map.queryRenderedFeatures(event.point, { layers: regionLayers.filter((id) => map.getLayoutProperty(id, 'visibility') !== 'none') })[0];
        if (region) {
            select(region.properties.shapeISO);
        }
    });

    // Where the street map's labels begin, so the regions go under them and the names stay readable.
    const labelsFrom = () => map.getStyle().layers.find((layer) => layer.type === 'symbol' && layer.layout?.['text-field'] && !layer.id.startsWith('water'))?.id;

    // The corners of a shape, [west, south], [east, north].
    const cornersOf = (geometry) => {
        let [west, south, east, north] = [Infinity, Infinity, -Infinity, -Infinity];
        const walk = (coordinates) => {
            if (typeof coordinates[0] === 'number') {
                [west, south, east, north] = [Math.min(west, coordinates[0]), Math.min(south, coordinates[1]), Math.max(east, coordinates[0]), Math.max(north, coordinates[1])];
            } else {
                coordinates.forEach(walk);
            }
        };
        walk(geometry.coordinates);
        return [[west, south], [east, north]];
    };

    const boundariesLoaded = fetch(container.dataset.boundaries).then((response) => {
        if (!response.ok) {
            throw new Error(response.statusText);
        }
        return response.json();
    });

    Promise.all([boundariesLoaded, ready]).then(([boundaries]) => {
        boundaryData = boundaries;
        for (const feature of boundaries.features) {
            regionBounds.set(feature.properties.shapeISO, cornersOf(feature.geometry));
        }

        const under = labelsFrom();
        // Each region's shade as paintRegions moves it along, in the soft blue until then.
        const regionShade = ['to-color', ['coalesce', ['feature-state', 'colour'], palette.normal]];
        map.addSource('regions', { type: 'geojson', data: boundaries, promoteId: 'shapeISO' });
        map.addLayer({
            id: 'region-fill', type: 'fill', source: 'regions',
            paint: { 'fill-color': regionShade, 'fill-opacity': regionOpacity() },
        }, under);
        // In 3D: each region standing up by its rate, in its shade, rising and falling from year to year.
        map.addLayer({
            id: 'region-3d', type: 'fill-extrusion', source: 'regions', layout: { visibility: 'none' },
            paint: {
                'fill-extrusion-color': regionShade,
                'fill-extrusion-height': ['coalesce', ['feature-state', 'height'], 0],
                'fill-extrusion-opacity': 0.88,
            },
        }, under);
        map.addLayer({
            id: 'region-line', type: 'line', source: 'regions',
            paint: { 'line-color': palette.outline, 'line-width': ['case', pointedAt, 2.5, 1.2] },
        }, under);
        // The one selected, outlined in the bright blue with a soft glow round it.
        map.addLayer({
            id: 'region-selected-glow', type: 'line', source: 'regions', filter: ['==', ['get', 'shapeISO'], ''],
            paint: { 'line-color': palette.selected, 'line-width': 12, 'line-blur': 8, 'line-opacity': 0.45 },
        });
        map.addLayer({
            id: 'region-selected', type: 'line', source: 'regions', filter: ['==', ['get', 'shapeISO'], ''],
            paint: { 'line-color': palette.selected, 'line-width': 3 },
        });

        // Buildings stand up in 3D close in, where the street map has their heights.
        if (map.getSource('openmaptiles')) {
            map.addLayer({
                id: 'buildings-3d', type: 'fill-extrusion', source: 'openmaptiles', 'source-layer': 'building', minzoom: 14,
                paint: {
                    'fill-extrusion-color': '#1f2a36',
                    'fill-extrusion-height': ['interpolate', ['linear'], ['zoom'], 14, 0, 15.5, ['coalesce', ['get', 'render_height'], 8]],
                    'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0],
                    'fill-extrusion-opacity': 0.85,
                },
            }, under);
        }

        credit('boundaries', `${t('Boundaries')}: geoBoundaries`, 'https://www.geoboundaries.org');

        // A region chosen before its shape was here, as a shared link or the palette can, is chosen again with it.
        select(selected);
    }).catch(() => showStatus(t("The states' boundaries couldn't load. Reload the page to try again.")));

    // Once the regions and the first year's timeline are in, Malaysia framed clear of the cards, the timeline and the
    // credits: flown in to from the globe, or straight away. Not when a region, district or station has been chosen.
    let firstShown = () => {};
    const timelineReady = new Promise((resolve) => { firstShown = resolve; });
    Promise.all([boundariesLoaded.catch(() => null), ready, container.dataset.crime ? timelineReady : null]).then(() => {
        if (!selected && !opening) {
            frame(malaysia, undefined, flyIn ? 2600 : 0);
        }
    });

    for (const [code, button] of choices) {
        button.addEventListener('click', () => select(code === selected ? null : code, { zoom: true }));
    }

    showAll.addEventListener('click', () => select(null, { zoom: true }));

    // The police districts' figures for the year shown, by "region|name", once they load, and how to open one.
    const districtsByKey = new Map();
    let openPin = () => false;

    // The map to a police district's pin, clear of the cards, without opening it. False if there's no such pin.
    const goToPin = (key) => {
        const district = districtsByKey.get(key);
        if (!district) {
            return false;
        }
        map.easeTo({ center: [district.lng, district.lat], zoom: Math.max(map.getZoom(), 11), padding: uncovered(), duration: duration(700), essential: true });
        return true;
    };

    // The search over the map, as in the Crimeify mockup: a state, police district or police station by name, or
    // "name, state" to narrow it to a state, like "Nilai, Negeri Sembilan". A state is chosen as from the list; a
    // police district's pin opens with its figures; a police station shows in "Find a police station", and the map
    // goes to its police district's pin. It searches the page's own lists, so it needs no server. As a combobox, the
    // arrow keys go through the matches, Enter picks one and Escape closes them.
    const stationList = document.querySelector('[data-station-list]');
    const stations = stationList
        ? Object.entries(JSON.parse(stationList.textContent)).flatMap(([region, list]) => list.map((station) => ({ ...station, region })))
        : [];
    const regionName = (code) => choices.get(code)?.dataset.name ?? code;
    const plain = (text) => text.toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();

    // Everything to search, with what each is and where: the regions, the police districts once their pins load, and
    // the police stations.
    const places = () => [
        ...[...choices.values()].map((button) => ({ kind: 'state', name: button.dataset.name, region: button.dataset.state, about: button.dataset.kind })),
        ...(crime?.districts ?? []).map((district) => ({
            kind: 'district', name: district.name, region: district.region, about: t('Police district in :region', { region: regionName(district.region) }),
        })),
        ...stations.map((station) => ({
            kind: 'station', name: station.name, region: station.region, station,
            about: t('Police station in :district, :region', { district: station.district, region: regionName(station.region) }),
        })),
    ];

    // The best matches first, at most eight: a name that starts with what's typed, then one with a word that does,
    // then one with it anywhere; regions before police districts before stations.
    const find = (query) => {
        const [name, where = ''] = query.split(',').map(plain);
        if (!name) {
            return [];
        }
        const kinds = ['state', 'district', 'station'];

        return places()
            .filter((place) => !where || plain(regionName(place.region)).includes(where))
            .map((place) => {
                const text = plain(place.name);
                return { place, rank: [text.startsWith(name), text.includes(` ${name}`), text.includes(name)].indexOf(true) };
            })
            .filter(({ rank }) => rank >= 0)
            .sort((a, b) => a.rank - b.rank || kinds.indexOf(a.place.kind) - kinds.indexOf(b.place.kind) || a.place.name.localeCompare(b.place.name))
            .slice(0, 8)
            .map(({ place }) => place);
    };

    // A police station chosen in "Find a police station", as if picked from its lists, which are Select2 boxes.
    const showStation = (station) => {
        const stateChoice = document.querySelector('[data-station-state]');
        const stationChoice = document.querySelector('[data-station-choice]');
        if (!stateChoice || !stationChoice) {
            return;
        }

        for (const [list, value] of [[stateChoice, station.region], [stationChoice, String(station.id)]]) {
            list.value = value;
            list.dispatchEvent(new Event('change', { bubbles: true }));
            window.jQuery?.fn.select2 && window.jQuery(list).trigger('change.select2');
        }

        // Brought into view, with the station's details: at the foot of the column over the map, or under the map on
        // a narrow screen.
        stationChoice.closest('.station-finder')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    // A place or address found by name (PlaceSearch): marked on the map, which goes to it, with the police districts
    // nearest it in "Near you", below, once that's set up.
    let nearPlace = null;
    let placeMarker = null;

    const goToPlace = (place) => {
        placeMarker?.remove();
        // White with a dark ring, which shows on any shade, and named, unlike the blue dot of where the visitor is.
        const mark = element('div', undefined, 'map-place');
        mark.append(element('span', place.name, 'map-place-name'), element('span', undefined, 'map-place-dot'));
        placeMarker = new maplibregl.Marker({ element: mark, anchor: 'bottom' }).setLngLat([place.lng, place.lat]).addTo(map);
        map.easeTo({ center: [place.lng, place.lat], zoom: Math.max(map.getZoom(), 13), padding: uncovered(), duration: duration(800), essential: true });
        nearPlace?.(place);
    };

    const go = (place) => {
        if (place.kind === 'place') {
            goToPlace(place);
        } else if (place.kind === 'state') {
            select(place.region, { zoom: true });
        } else if (place.kind === 'district') {
            select(place.region);
            openPin(`${place.region}|${place.name}`);
        } else {
            showStation(place.station);
            // To its police district's pin, or without one, its state.
            if (goToPin(`${place.region}|${place.station.district}`)) {
                select(place.region);
            } else {
                select(place.region, { zoom: true });
            }
        }
    };

    if (search) {
        const input = search.querySelector('input');
        const list = search.querySelector('[role="listbox"]');
        const none = search.querySelector('[data-map-search-none]');
        let found = [];
        let active = -1;

        const mark = (index) => {
            active = index;
            [...list.children].forEach((option, at) => option.setAttribute('aria-selected', String(at === index)));
            if (list.children[index]) {
                input.setAttribute('aria-activedescendant', list.children[index].id);
                list.children[index].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        };

        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            mark(-1);
        };

        const pick = (place) => {
            input.value = place.kind === 'state' ? place.name : `${place.name}, ${place.kind === 'place' ? place.about : regionName(place.region)}`;
            none.textContent = '';
            close();
            go(place);
        };

        // Places and addresses by name from the server (PlaceSearch), after the page's own matches: asked a moment after
        // typing stops, from three letters, and kept for what was typed, so going back to it doesn't ask again.
        const placesUrl = search.dataset.places;
        const placesFor = new Map();
        let placeTimer = null;
        let placeRequest = null;

        const askPlaces = (text) => {
            placeRequest?.abort();
            placeRequest = new AbortController();
            const url = new URL(placesUrl, window.location.href);
            url.searchParams.set('q', text);

            fetch(url, { headers: { Accept: 'application/json' }, signal: placeRequest.signal })
                .then((response) => (response.ok ? response.json() : { places: [], error: true }))
                .catch((problem) => (problem.name === 'AbortError' ? null : { places: [], error: true }))
                .then((answer) => {
                    if (!answer) {
                        return;
                    }
                    placesFor.set(text, answer);
                    // Photon's, credited on the map once its places are shown.
                    if (!credits.has('places') && answer.places.length > 0) {
                        credit('places', t('Search: Photon by komoot'), search.dataset.placesAbout);
                    }
                    if (input.value.trim() === text && document.activeElement === input) {
                        suggest();
                    }
                });
        };

        const suggest = () => {
            const text = input.value.trim();
            const asked = placesFor.get(text);
            const fromServer = (asked?.places ?? []).map((place) => ({ ...place, kind: 'place' }));
            found = [...find(input.value), ...fromServer];
            list.replaceChildren(...found.map((place, index) => {
                const option = element('li', undefined, place.kind === 'place' ? 'map-search-place' : undefined);
                option.id = `map-search-option-${index}`;
                option.setAttribute('role', 'option');
                option.append(element('span', place.name, 'map-search-name'), element('span', place.about, 'map-search-kind'));
                option.addEventListener('click', () => pick(place));
                return option;
            }));
            list.hidden = found.length === 0;
            input.setAttribute('aria-expanded', String(found.length > 0));

            // Nothing yet: still asking, or asking didn't work, or there's nothing.
            const waiting = placesUrl && text.length >= 3 && !asked;
            none.textContent = found.length > 0 ? ''
                : waiting ? t('Searching places…')
                    : asked?.error ? t("Places can't be searched just now. Try again in a moment.")
                        : t('No matches for “:text”', { text });
            mark(-1);
        };

        input.addEventListener('input', () => {
            const text = input.value.trim();
            clearTimeout(placeTimer);

            if (text) {
                suggest();
                if (placesUrl && text.length >= 3 && !placesFor.has(text)) {
                    placeTimer = setTimeout(() => askPlaces(text), 300);
                }
            } else {
                none.textContent = '';
                close();
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (list.hidden && input.value.trim()) {
                    suggest();
                }
                if (found.length > 0) {
                    event.preventDefault();
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    mark(active < 0 ? (step > 0 ? 0 : found.length - 1) : (active + step + found.length) % found.length);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                close();
            }
        });

        // Picking with the mouse mustn't take the focus from the box first, which would close the list; leaving the
        // search does close it.
        list.addEventListener('pointerdown', (event) => event.preventDefault());
        input.addEventListener('blur', (event) => {
            if (!search.contains(event.relatedTarget)) {
                none.textContent = '';
                close();
            }
        });

        search.addEventListener('submit', (event) => {
            event.preventDefault();
            if (list.hidden && input.value.trim()) {
                suggest();
            }
            const place = found[active] ?? found[0];
            if (place) {
                pick(place);
            }
        });
    }

    // "Near you" (nearby.js): with the browser's location, from the button in its card or the one over the map, or a
    // place found with the search, the state it's in, chosen as from the list, and the three police districts nearest
    // it, each with its stations, its profile and its pin. MapLibre's location button shows where the visitor is, with a
    // circle for how accurate that is, and follows them as they move; the list is worked out again once they've moved a
    // little. The location stays in the browser. Without a browser that can give one, the card is for places only.
    const near = document.querySelector('[data-near]');
    const nearby = window.CrimeifyNearby;
    const pinList = document.querySelector('[data-district-pins]');

    if (near && nearby && pinList) {
        const pins = JSON.parse(pinList.textContent);
        const results = near.querySelector('[data-near-results]');
        const heading = near.querySelector('#near-heading');
        // Where the list was last worked out for, so it isn't redone for every step.
        let listedAt = null;

        const say = (text, className = 'near-problem') => results.replaceChildren(element('p', text, className));

        const link = (text, href, outside = false) => {
            const anchor = element('a', text);
            anchor.href = href;
            if (outside) {
                anchor.target = '_blank';
                anchor.rel = 'noopener';
            }
            return anchor;
        };

        // A police district near the visitor: its name, how far, its state, its profile, its pin, and its stations.
        const nearDistrict = (pin) => {
            const item = element('li');
            const head = element('p', undefined, 'near-district');
            head.append(element('strong', pin.name), ' ', element('span', `${nearby.distance(pin.km)} · ${regionName(pin.region)}`, 'muted'));

            const onMap = element('button', t('Show on the map'), 'near-link');
            onMap.type = 'button';
            onMap.addEventListener('click', () => {
                // On a narrow screen the map is above the cards, out of view.
                if (narrow.matches) {
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                go({ kind: 'district', region: pin.region, name: pin.name });
            });
            const links = element('p', undefined, 'near-links');
            links.append(link(t('Area profile'), pin.profile), ' · ', onMap);
            item.append(head, links);

            const here = stations.filter((station) => station.region === pin.region && station.district === pin.name);
            if (here.length > 0) {
                const list = element('ul', undefined, 'near-stations');
                for (const station of here) {
                    const row = element('li');
                    row.append(element('span', station.name));
                    if (station.call) {
                        row.append(' · ', link(station.phone, station.call));
                    }
                    row.append(' · ', link('Waze', station.waze, true));
                    list.append(row);
                }
                item.append(list);
            }

            return item;
        };

        // For where the visitor is, or with place, a place found with the search.
        const list = (turf, latlng, { accuracy = null, place = null } = {}) => {
            const state = boundaryData ? nearby.within(turf, boundaryData, latlng.lat, latlng.lng) : null;
            const code = state?.properties.shapeISO ?? null;
            heading.textContent = place ? t('Near :place', { place: place.name }) : t('Near you');

            const where = element('p', undefined, 'near-where');
            if (place) {
                where.append(place.name, ' ', element('span', place.about, 'muted'));
            } else {
                where.textContent = code ? t("You're in :region.", { region: regionName(code) }) : t("You don't seem to be in Malaysia's states.");
            }
            if (accuracy) {
                where.append(' ', element('span', t('Accurate to about :distance.', { distance: nearby.distance(accuracy / 1000) }), 'muted'));
            }

            const districts = element('ol', undefined, 'near-list');
            districts.append(...nearby.nearest(turf, pins, latlng.lat, latlng.lng).map(nearDistrict));
            results.replaceChildren(
                where,
                element('h3', t('Nearest police districts'), 'near-heading'),
                districts,
                element('p', t("In a straight line to each district's pin, mostly its police headquarters. Near a district's edge, the nearest pin may be a neighbouring district's."), 'near-note'),
            );

            // The first time, or for a place, the state is chosen, as from the list, and the list brought into view.
            if (listedAt === null || place) {
                if (code && code !== selected) {
                    select(code);
                }
                results.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };

        nearPlace = (place) => nearby.load(container.dataset.turf).then(
            (turf) => list(turf, { lat: place.lat, lng: place.lng }, { place }),
            () => say(t("Couldn't work out what's near you. Reload the page to try again.")),
        );
        near.hidden = false;

        // Where the visitor is, where the browser can give it.
        if (!('geolocation' in navigator)) {
            near.querySelector('[data-near-button]').hidden = true;
            near.querySelector('.near-privacy').hidden = true;
        } else {
            const locate = new maplibregl.GeolocateControl({
                positionOptions: { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
                trackUserLocation: true,
                showAccuracyCircle: true,
                fitBoundsOptions: { maxZoom: 12, padding: uncovered() },
            });
            map.addControl(locate, 'bottom-right');

            locate.on('trackuserlocationstart', () => {
                listedAt = null;
                say(t('Finding where you are…'), 'muted');
            });

            locate.on('geolocate', ({ coords }) => {
                const latlng = { lat: coords.latitude, lng: coords.longitude };
                nearby.load(container.dataset.turf).then((turf) => {
                    if (listedAt === null || turf.distance(turf.point([listedAt.lng, listedAt.lat]), turf.point([latlng.lng, latlng.lat])) > 0.2) {
                        list(turf, latlng, { accuracy: coords.accuracy });
                        listedAt = latlng;
                    }
                }, () => say(t("Couldn't work out what's near you. Reload the page to try again.")));
            });

            // In the card, rather than the browser's alert boxes.
            locate.on('error', (error) => say(nearby.problem(error)));
            locate.on('outofmaxbounds', () => say(t("You seem to be outside Malaysia, so there's nothing near you on this map.")));

            near.querySelector('[data-near-button]').addEventListener('click', () => locate.trigger());
        }
    }

    // A police district or station given in the address, from a shared link or the command palette: the district's pin
    // opened, or the station shown in "Find a police station" and the map at its district, once the pins are in
    // (show, below), or straight away without them.
    const openGiven = () => {
        if (opening?.kind === 'district') {
            go({ kind: 'district', region: opening.region, name: opening.name });
        } else if (opening?.kind === 'station') {
            const station = stations.find((candidate) => candidate.id === opening.id);
            if (station) {
                go({ kind: 'station', region: station.region, station });
            }
        }
    };

    if (!container.dataset.crime) {
        ready.then(openGiven);
        return;
    }

    // The police districts' pins, as dots in the app's bright blue with a white ring and a soft glow, the open one
    // ringed larger. Pins close together are grouped into a numbered circle until zoomed in: Kuala Lumpur's police
    // districts are only a few kilometres apart. The groups are blue too: green, yellow and orange would read as how
    // much crime there is, when they only say how many pins.
    // A bubble's size as the map zooms: smaller far out, where districts crowd, and bigger close in.
    const bySize = (radius) => ['interpolate', ['linear'], ['zoom'], 4, ['*', radius, 0.55], 6, radius, 9, ['*', radius, 1.7], 12, ['*', radius, 2.4]];

    const pinsReady = ready.then(() => {
        map.addSource('districts', { type: 'geojson', data: { type: 'FeatureCollection', features: [] }, cluster: true, clusterRadius: 45, clusterMaxZoom: 10 });
        map.addSource('open-pin', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

        const grouped = ['has', 'point_count'];
        map.addLayer({
            id: 'district-clusters', type: 'circle', source: 'districts', filter: grouped,
            paint: {
                'circle-color': palette.selected,
                'circle-radius': ['step', ['get', 'point_count'], 15, 5, 19, 15, 24],
                'circle-stroke-width': 6,
                'circle-stroke-color': 'rgba(58, 170, 242, 0.3)',
            },
        });
        map.addLayer({
            id: 'district-cluster-count', type: 'symbol', source: 'districts', filter: grouped,
            layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': ['Noto Sans Bold'], 'text-size': 13, 'text-allow-overlap': true },
            paint: { 'text-color': '#0b1a26' },
        });
        map.addLayer({
            id: 'district-glow', type: 'circle', source: 'districts', filter: ['!', grouped],
            paint: { 'circle-color': palette.selected, 'circle-radius': 13, 'circle-blur': 0.9, 'circle-opacity': 0.55 },
        });
        map.addLayer({
            id: 'district-pins', type: 'circle', source: 'districts', filter: ['!', grouped],
            paint: { 'circle-color': palette.selected, 'circle-radius': 6.5, 'circle-stroke-width': 2.5, 'circle-stroke-color': '#ffffff' },
        });
        map.addLayer({
            id: 'district-open', type: 'circle', source: 'open-pin',
            paint: { 'circle-color': 'rgba(0, 0, 0, 0)', 'circle-radius': 13, 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' },
        });

        // The police districts the map's AI points out: a violet ring round each, and its number beside it.
        map.addSource('ai-picks', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addLayer({
            id: 'ai-pick-ring', type: 'circle', source: 'ai-picks',
            paint: { 'circle-color': 'rgba(177, 140, 255, 0.16)', 'circle-radius': 17, 'circle-stroke-width': 3, 'circle-stroke-color': palette.picked },
        });
        map.addLayer({
            id: 'ai-pick-number', type: 'symbol', source: 'ai-picks',
            layout: { 'text-field': ['get', 'number'], 'text-font': ['Noto Sans Bold'], 'text-size': 12, 'text-offset': [1.6, -1.4], 'text-allow-overlap': true },
            paint: { 'text-color': '#ffffff', 'text-halo-color': palette.pickedFill, 'text-halo-width': 4 },
        });

        // The bubbles: one for each police district, not grouped, sized by a crime's count, each round its own pin, the
        // biggest underneath so the small ones stay in sight; and round each, an unseen circle to point at. In the
        // app's bright blue, as the pins are: how big says how many.
        map.addSource('bubbles', { type: 'geojson', data: { type: 'FeatureCollection', features: [] }, promoteId: 'key' });
        const size = ['coalesce', ['feature-state', 'r'], 0];
        map.addLayer({
            id: 'bubbles', type: 'circle', source: 'bubbles',
            layout: { visibility: 'none', 'circle-sort-key': ['-', 0, ['get', 'count']] },
            paint: {
                'circle-radius': bySize(size),
                'circle-color': palette.selected,
                'circle-opacity': 0.3,
                'circle-stroke-color': '#9fd6fb',
                'circle-stroke-width': 1.2,
                'circle-stroke-opacity': 0.9,
            },
        });
        map.addLayer({
            id: 'bubble-hits', type: 'circle', source: 'bubbles', layout: { visibility: 'none' },
            paint: { 'circle-radius': bySize(['max', size, 9]), 'circle-opacity': 0 },
        });
        map.on('click', 'bubble-hits', (event) => openPin(event.features[0].properties.key));

        // The ripples, as the year changes: rings growing out from districts where the crime rose, in its orange, and
        // shrinking in to those where it fell, in teal, each edged dark so it shows on any shade, and the change by it.
        // Growing or shrinking, and + or −, say which as well as the colour, which not everyone can tell apart.
        map.addSource('ripples', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        const way = (up, down) => ['match', ['get', 'way'], 'up', up, down];
        map.addLayer({
            id: 'ripple-casing', type: 'circle', source: 'ripples',
            paint: { 'circle-radius': 0, 'circle-opacity': 0, 'circle-stroke-width': 5, 'circle-stroke-color': '#0b0f14', 'circle-stroke-opacity': 0 },
        });
        map.addLayer({
            id: 'ripple', type: 'circle', source: 'ripples',
            paint: { 'circle-radius': 0, 'circle-opacity': 0, 'circle-stroke-width': 2.5, 'circle-stroke-color': way(palette.rose, palette.fell), 'circle-stroke-opacity': 0 },
        });
        map.addLayer({
            id: 'ripple-label', type: 'symbol', source: 'ripples',
            layout: { 'text-field': ['get', 'label'], 'text-font': ['Noto Sans Bold'], 'text-size': 11, 'text-anchor': 'bottom', 'text-offset': [0, -1.3] },
            paint: { 'text-color': way(palette.rose, palette.fell), 'text-halo-color': '#0b0f14', 'text-halo-width': 1.6, 'text-opacity': 0 },
        });

        // A group opens up to its pins.
        map.on('click', 'district-clusters', async (event) => {
            const group = event.features[0];
            const zoom = await map.getSource('districts').getClusterExpansionZoom(group.properties.cluster_id);
            map.easeTo({ center: group.geometry.coordinates, zoom, duration: duration(500) });
        });
        map.on('click', 'district-pins', (event) => openPin(event.features[0].properties.key));
    });

    // A district's count of a crime: "all", a category or a crime type; the year before's with before. Null without
    // figures for that year.
    const categoryOf = (type) => Object.entries(crime.types).find(([, types]) => type in types)?.[0];
    const countOf = (district, key, before = false) => {
        const totals = before ? district.previous : district.totals;
        if (!totals) {
            return null;
        }
        if (key === 'all') {
            return Object.values(totals).reduce((sum, value) => sum + value, 0);
        }
        if (key in totals) {
            return totals[key];
        }
        return (before ? district.previousTypes : district.types)?.[categoryOf(key)]?.[key] ?? 0;
    };
    const crimeName = (key) => (key === 'all' ? t('Violent and property crime') : crime.categories[key] ?? crime.types[categoryOf(key)]?.[key] ?? key);

    // A bubble's radius at the country's zoom: its area is its count, the most any district had of that crime in a year
    // 26 px across the radius.
    const largest = 26;
    const radiusOf = (crimes, most) => largest * Math.sqrt(Math.max(0, crimes) / Math.max(1, most));

    // The bubbles: plain pins again, or each district's bubble growing or shrinking to its count this year.
    const bubbleNow = new Map();
    const sizeBubbles = () => {
        if (!map.getLayer('bubbles')) {
            return;
        }
        const on = bubbled();
        for (const id of ['bubbles', 'bubble-hits']) {
            map.setLayoutProperty(id, 'visibility', on ? 'visible' : 'none');
        }
        for (const id of ['district-clusters', 'district-cluster-count', 'district-glow', 'district-pins']) {
            map.setLayoutProperty(id, 'visibility', on ? 'none' : 'visible');
        }
        drawPinsKey();
        if (!on) {
            return;
        }

        map.getSource('bubbles').setData({
            type: 'FeatureCollection',
            features: [...districtsByKey].map(([key, district]) => ({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [district.lng, district.lat] },
                properties: { key, name: district.name, count: countOf(district, sizing) ?? 0 },
            })),
        });

        const most = crime.mostInADistrict?.[sizing] ?? 1;
        const targets = new Map([...districtsByKey].map(([key, district]) => [key, radiusOf(countOf(district, sizing) ?? 0, most)]));
        const starts = new Map([...targets.keys()].map((key) => [key, bubbleNow.get(key) ?? 0]));
        animate('bubbles', 900, (part) => {
            for (const [key, target] of targets) {
                const radius = between(starts.get(key), target, part);
                bubbleNow.set(key, radius);
                map.setFeatureState({ source: 'bubbles', id: key }, { r: radius });
            }
        });
    };

    // The ripples for the year shown: where the crime the pins show, or all of it, changed by at least 10% and at
    // least 10 crimes from the year before, so a small district's few crimes don't set them off. Out and in, then
    // gone, in a second and a bit; without motion, the rings and changes simply show for a moment.
    const threshold = { percent: 10, crimes: 10 };
    const ripple = () => {
        if (!map.getSource('ripples') || crime.byState || crime.previousYear === null) {
            return;
        }

        const key = sizing ?? 'all';
        const features = [];
        for (const district of districtsByKey.values()) {
            const [now, before] = [countOf(district, key), countOf(district, key, true)];
            if (now === null || before === null || before === 0) {
                continue;
            }
            const change = ((now - before) / before) * 100;
            if (Math.abs(change) >= threshold.percent && Math.abs(now - before) >= threshold.crimes) {
                features.push({
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [district.lng, district.lat] },
                    properties: { way: change > 0 ? 'up' : 'down', label: `${change > 0 ? '+' : '−'}${Math.round(Math.abs(change))}%` },
                });
            }
        }
        map.getSource('ripples').setData({ type: 'FeatureCollection', features });

        const rings = (up, down, opacity, labels) => {
            for (const id of ['ripple', 'ripple-casing']) {
                map.setPaintProperty(id, 'circle-radius', ['match', ['get', 'way'], 'up', up, down]);
                map.setPaintProperty(id, 'circle-stroke-opacity', opacity);
            }
            map.setPaintProperty('ripple-label', 'text-opacity', labels);
        };

        if (!motion) {
            cancelAnimationFrame(frames.get('ripples'));
            rings(14, 14, 0.9, 1);
            clearTimeout(frames.get('ripples-off'));
            frames.set('ripples-off', setTimeout(() => rings(0, 0, 0, 0), 1600));
            return;
        }

        // Linear, so they ripple evenly: in fast, then fading out as they go.
        cancelAnimationFrame(frames.get('ripples'));
        const start = performance.now();
        const tick = (now) => {
            const part = Math.min(1, Math.max(0, (now - start) / 1300));
            const fade = part < 0.15 ? part / 0.15 : (1 - part) / 0.85;
            rings(4 + 20 * part, 24 - 20 * part, 0.9 * fade, part < 0.7 ? 1 : (1 - part) / 0.3);
            if (part < 1) {
                frames.set('ripples', requestAnimationFrame(tick));
            }
        };
        frames.set('ripples', requestAnimationFrame(tick));
    };

    // "Pins show", under the year in the panel: plain pins, or bubbles of a crime, with its size key; and what the
    // ripples mean.
    let pinsBox = null;

    const buildPinsBox = () => {
        const field = yearChoice?.closest('.crime-year');
        if (!field) {
            return;
        }

        pinsBox = element('div', undefined, 'map-pins');
        const label = element('label', t('Pins show'));
        label.htmlFor = 'map-pins-choice';
        const choice = element('select');
        choice.id = 'map-pins-choice';
        // A small list of the map's own, not a Select2 box.
        choice.dataset.native = '';
        const option = (value, text) => {
            const item = element('option', text);
            item.value = value;
            return item;
        };
        choice.append(option('', t('Where each police district is')), option('all', t('Violent and property crime')));
        for (const [category, label] of Object.entries(crime.categories)) {
            const group = element('optgroup');
            group.label = label;
            group.append(option(category, t(':category, every type', { category: label })), ...Object.entries(crime.types[category] ?? {}).map(([type, name]) => option(type, name)));
            choice.append(group);
        }
        choice.addEventListener('change', () => {
            sizing = choice.value || null;
            tip.remove();
            sizeBubbles();
        });
        pinsChoice = choice;

        const key = element('div', undefined, 'map-pins-key');
        key.hidden = true;
        const rings = element('p', undefined, 'map-pins-rings');
        rings.append(
            element('span', undefined, 'map-ring map-ring-up'), t('Up'), ' ',
            element('span', undefined, 'map-ring map-ring-down'), t('Down'), ' ',
            element('span', t('Rings as the year changes: :percent% or more, and :crimes crimes, on the year before.', threshold), 'map-pins-note'),
        );

        pinsBox.append(label, choice, key, rings);
        field.after(pinsBox);
    };

    // The size key: three bubbles as big as on the map at this zoom, with their counts, none bigger than fits.
    const zoomFactor = (zoom) => {
        const stops = [[4, 0.55], [6, 1], [9, 1.7], [12, 2.4]];
        const [low, high] = [stops.findLast(([at]) => at <= zoom) ?? stops[0], stops.find(([at]) => at >= zoom) ?? stops.at(-1)];
        return low[0] === high[0] ? low[1] : between(low[1], high[1], (zoom - low[0]) / (high[0] - low[0]));
    };
    const round = (value) => {
        const place = 10 ** Math.floor(Math.log10(value));
        return Math.round(value / place) * place;
    };

    function drawPinsKey() {
        const key = pinsBox?.querySelector('.map-pins-key');
        if (!key) {
            return;
        }
        key.hidden = !bubbled();
        if (key.hidden) {
            return;
        }

        const most = crime.mostInADistrict?.[sizing] ?? 0;
        const factor = zoomFactor(map.getZoom());
        // The largest count shown: the most there is, or less, so its bubble is no more than 64 px across.
        const top = Math.min(most, most * (32 / (largest * factor)) ** 2);
        const counts = [...new Set([top, top / 4, top / 16].filter((value) => value >= 1).map(round))];

        const sizes = element('div', undefined, 'map-pins-sizes');
        sizes.setAttribute('aria-hidden', 'true');
        sizes.append(...counts.map((value) => {
            const item = element('span', undefined, 'map-pins-size');
            const dot = element('span', undefined, 'map-pins-dot');
            const across = 2 * radiusOf(value, most) * factor;
            dot.style.width = `${across}px`;
            dot.style.height = `${across}px`;
            item.append(dot, element('span', count.format(value)));
            return item;
        }));
        key.replaceChildren(
            sizes,
            element('p', t(':crime in :year, by police district. Each bubble’s area is its count, not per person.', { crime: crimeName(sizing), year: crime.year }), 'map-pins-note'),
        );
    }
    map.on('zoomend', () => sizing && drawPinsKey());

    const markOpen = (at) => {
        map.getSource('open-pin')?.setData({ type: 'FeatureCollection', features: at ? [{ type: 'Feature', geometry: { type: 'Point', coordinates: at }, properties: {} }] : [] });
    };

    // A police district's pin opened, as the search does: the map moved to it first if it's out of view or in a group.
    openPin = (key) => {
        const district = districtsByKey.get(key);
        if (!district) {
            return false;
        }

        const at = [district.lng, district.lat];
        const show = () => {
            pinPopup?.remove();
            const shown = new maplibregl.Popup({ maxWidth: '300px', className: 'crime-popup-shell', offset: 14, focusAfterOpen: true })
                .setLngLat(at)
                .setDOMContent(popup(district))
                .addTo(map);
            pinPopup = shown;
            open = key;
            markOpen(at);
            shown.on('close', () => {
                if (pinPopup === shown) {
                    pinPopup = null;
                    open = null;
                    markOpen(null);
                }
            });
            requestAnimationFrame(() => keepInView(shown.getElement()));
        };

        if (map.getZoom() > 10 && map.getBounds().contains(at)) {
            show();
        } else {
            map.once('moveend', show);
            map.easeTo({ center: at, zoom: Math.max(map.getZoom(), 11), padding: uncovered(), duration: duration(700), essential: true });
        }
        return true;
    };

    // The timeline over the bottom of the map, as in a video player: ▶ plays the years from the first, one every second
    // and a half, and the slider goes to any of them, as the year list in the panel does. Beside it, the legend: what
    // the map is shaded by, where each shade begins, and 3D. It's built with the first figures, which give the years.
    let years = [];
    let slider = null;
    let yearLabel = null;
    let playButton = null;
    let legend = null;
    let raiseButton = null;
    let playing = false;
    let nextStep = null;
    const pace = 1500;

    const labelPlay = () => {
        const text = t(playing ? 'Pause' : 'Play the years');
        playButton.setAttribute('aria-label', text);
        playButton.title = text;
        playButton.firstChild.textContent = playing ? 'pause' : 'play_arrow';
    };

    const syncTimeline = () => {
        if (slider) {
            slider.value = String(years.indexOf(shownYear));
            slider.setAttribute('aria-valuetext', String(shownYear));
            yearLabel.textContent = shownYear;
        }
    };

    // The legend for what the map is shaded by: the shades in a strip, with the rate where each begins under it, and
    // "No figures" when a region has no rate in the year shown.
    const drawLegend = () => {
        if (!legend) {
            return;
        }

        legend.hidden = !shading;
        if (!shading) {
            return;
        }

        const limits = rates.scale[measure] ?? [];
        const bands = limits.length + 1;
        legend.querySelector('.map-legend-scale').replaceChildren(...Array.from({ length: bands }, (_, band) => {
            const swatch = element('span');
            swatch.style.background = shadeAt(band, limits);
            return swatch;
        }));
        legend.querySelector('.map-legend-limits').replaceChildren(...limits.map((limit, at) => {
            const mark = element('span', perPeople(limit));
            mark.style.setProperty('--at', (at + 1) / bands);
            return mark;
        }));
        legend.querySelector('.map-legend-read').textContent = limits.length
            ? t('Shaded from under :low to :high and over.', { low: perPeople(limits[0]), high: perPeople(limits.at(-1)) })
            : '';

        legend.querySelector('.map-legend-none').hidden = [...choices.keys()].every((code) => rateOf(code) !== null);
    };

    // Every region in the shade of its rate in the year shown, an open tooltip with it, and the legend.
    const paint = () => {
        paintRegions();
        if (tip.isOpen() && tipPin) {
            districtsByKey.has(tipPin) ? tip.setDOMContent(pinTip(tipPin)) : tip.remove();
        } else if (tip.isOpen() && hovered) {
            tip.setDOMContent(regionTip(hovered));
        }
        drawLegend();
    };

    // 3D: the regions stand up by their rate, and the map tilts to show all of Malaysia, clear of the cards; flat
    // again, it faces north where it is.
    const raise = (on) => {
        raised = on;
        raiseButton.setAttribute('aria-pressed', String(on));
        if (!regionsDrawn()) {
            return;
        }
        map.setLayoutProperty('region-3d', 'visibility', on ? 'visible' : 'none');
        map.setLayoutProperty('region-fill', 'visibility', on ? 'none' : 'visible');

        const whole = on ? map.cameraForBounds(malaysia, { padding: uncovered() }) : null;
        map.easeTo({
            ...(whole ? { center: whole.center, zoom: whole.zoom } : {}),
            pitch: on ? 50 : 0, bearing: on ? -12 : 0, duration: duration(1000), essential: true,
        });
    };

    const buildTimeline = () => {
        timeline = element('div', undefined, 'map-timeline');
        timeline.setAttribute('role', 'group');
        timeline.setAttribute('aria-label', t('Crime per 100,000 people, year by year'));

        const player = element('div', undefined, 'map-timeline-player');
        player.hidden = years.length < 2;

        playButton = element('button', undefined, 'map-play');
        playButton.type = 'button';
        playButton.append(element('span', '', 'material-icon'));
        playButton.firstChild.setAttribute('aria-hidden', 'true');
        playButton.addEventListener('click', () => (playing ? stop() : play()));

        // The year in large type, which the slider gives screen readers itself.
        yearLabel = element('span', '', 'map-timeline-year');
        yearLabel.setAttribute('aria-hidden', 'true');

        const track = element('div', undefined, 'map-timeline-track');
        slider = element('input');
        Object.assign(slider, { type: 'range', min: '0', max: String(years.length - 1), step: '1' });
        slider.setAttribute('aria-label', t('Year'));
        slider.addEventListener('input', () => {
            stop();
            choose(years[Number(slider.value)]);
        });

        // Each year under its place on the slider.
        const ticks = element('div', undefined, 'map-timeline-ticks');
        ticks.setAttribute('aria-hidden', 'true');
        years.forEach((year, at) => {
            const tick = element('span', String(year));
            tick.style.setProperty('--at', years.length > 1 ? at / (years.length - 1) : 0);
            ticks.append(tick);
        });
        track.append(slider, ticks);
        player.append(playButton, yearLabel, track);

        legend = element('div', undefined, 'map-legend');
        const heading = element('div', undefined, 'map-legend-heading');
        const label = element('label');
        const choice = element('select');
        // A small list of the map's own, not a Select2 box.
        choice.dataset.native = '';
        for (const [value, text] of Object.entries(rates.measures)) {
            const option = element('option', text);
            option.value = value;
            choice.append(option);
        }
        choice.value = measure;
        choice.addEventListener('change', () => {
            measure = choice.value;
            paint();
            describe(selected);
        });
        measureChoice = choice;
        label.append(element('span', t('Shade the map by'), 'visually-hidden'), choice, element('span', t('per 100,000 people')));

        // 3D, with what its height means, as its colour does.
        raiseButton = element('button', undefined, 'map-raise');
        raiseButton.type = 'button';
        raiseButton.setAttribute('aria-pressed', 'false');
        raiseButton.title = t('Stand each state up by its crime per 100,000 people: taller is more.');
        raiseButton.append(element('span', 'view_in_ar', 'material-icon'), '3D');
        raiseButton.firstChild.setAttribute('aria-hidden', 'true');
        raiseButton.addEventListener('click', () => raise(!raised));
        heading.append(label, raiseButton);

        const scale = element('div', undefined, 'map-legend-scale');
        const limits = element('div', undefined, 'map-legend-limits');
        scale.setAttribute('aria-hidden', 'true');
        limits.setAttribute('aria-hidden', 'true');
        const none = element('p', undefined, 'map-legend-none');
        none.append(element('span', '', 'map-legend-swatch'), t('No figures'));
        legend.append(heading, scale, limits, element('p', '', 'visually-hidden map-legend-read'), none);

        timeline.append(player, legend);
        container.append(timeline);
        labelPlay();
        placeTimeline();
    };

    // Each year's figures, kept once loaded, so going back to a year or playing them again doesn't wait for them.
    const loaded = new Map();
    let latest = 0;

    const fetchYear = (year) => {
        const key = String(year ?? '');

        if (!loaded.has(key)) {
            const url = new URL(container.dataset.crime, window.location.href);
            if (year) {
                url.searchParams.set('year', year);
            }

            loaded.set(key, fetch(url, { headers: { Accept: 'application/json' } })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    return response.json();
                })
                .catch((problem) => {
                    // Tried again next time.
                    loaded.delete(key);
                    throw problem;
                }));
        }

        return loaded.get(key);
    };

    let shownBefore = null;

    const show = (figures) => {
        // A popup open when the year changes opens again with the new year's figures.
        const reopen = open;
        crime = figures;
        rates = figures.rates ?? null;
        shading = Boolean(rates) && Object.values(rates.years).some((year) => Object.keys(year.regions).length > 0);
        years = figures.years;
        shownYear = figures.year;
        districtsByKey.clear();

        if (status.dataset.part === 'crime') {
            status.hidden = true;
        }

        const first = !timeline && rates && years.length > 0;
        if (first) {
            buildTimeline();
        }
        if (!pinsBox) {
            buildPinsBox();
        }
        // Another year than the last shown, rather than the first or the same again.
        const changed = shownBefore !== null && shownBefore !== figures.year;
        shownBefore = figures.year;

        for (const district of figures.districts) {
            districtsByKey.set(`${district.region}|${district.name}`, district);
        }
        map.getSource('districts').setData({
            type: 'FeatureCollection',
            features: figures.districts.map((district) => ({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [district.lng, district.lat] },
                properties: { key: `${district.region}|${district.name}`, name: district.name },
            })),
        });

        pinPopup?.remove();
        if (reopen) {
            openPin(reopen);
        }

        sizeBubbles();
        if (changed) {
            ripple();
        }

        // The figures' source: data.gov.my's, linked, or for a year by state, the police's crime index. And with rates,
        // DOSM's, for the population.
        credit('figures', figures.credit, figures.about);
        credit('population', shading ? rates.credit : null, rates?.about);

        syncTimeline();
        paint();
        placeTimeline();
        describe(selected);

        // The district or station the address gives, once its pins are here.
        if (first) {
            openGiven();
        }
        firstShown();
        markReady();
    };

    // A year's figures, shown once they're in and the map's ready for them, unless another year has been asked for
    // meanwhile.
    const load = (year) => {
        const ticket = ++latest;

        return Promise.all([fetchYear(year), pinsReady])
            .then(([figures]) => {
                if (ticket === latest) {
                    show(figures);
                }
            })
            .catch(() => {
                firstShown();
                if (ticket === latest) {
                    stop();
                    showStatus(t("The crime figures couldn't load. Choose the year again, or reload the page."), 'crime');
                }
            });
    };

    // A year chosen on the timeline or in the year list: the other one moves to it, the regions take its shades straight
    // away, fading into them, and its pins and the panel follow once its figures are in.
    const choose = (year, { fromList = false } = {}) => {
        shownYear = year;

        if (!fromList && yearChoice) {
            yearChoice.value = String(year);
            // Only Select2's box, not the change event, which would choose it again.
            window.jQuery?.fn.select2 && window.jQuery(yearChoice).trigger('change.select2');
        }

        syncTimeline();
        paint();
        return load(year);
    };

    const stop = () => {
        if (!playing) {
            return;
        }
        playing = false;
        clearTimeout(nextStep);
        details.setAttribute('aria-live', 'polite');
        labelPlay();
    };

    // The next year, once this one has shown for a while, until the last. The one after it loads meanwhile.
    const advance = () => {
        const next = years.indexOf(shownYear) + 1;

        if (next >= years.length) {
            stop();
            return;
        }

        choose(years[next]).then(() => {
            if (playing) {
                if (next + 1 < years.length) {
                    fetchYear(years[next + 1]).catch(() => {});
                }
                nextStep = setTimeout(advance, pace);
            }
        });
    };

    // From the first year, or from where the timeline is if that's part way.
    const play = () => {
        playing = true;
        // The panel is read out once it stops, rather than every year.
        details.setAttribute('aria-live', 'off');
        labelPlay();

        const start = shownYear === years.at(-1) ? choose(years[0]) : Promise.resolve();
        start.then(() => {
            if (playing) {
                const next = years.indexOf(shownYear) + 1;
                if (next < years.length) {
                    fetchYear(years[next]).catch(() => {});
                }
                nextStep = setTimeout(advance, pace);
            }
        });
    };

    yearChoice?.addEventListener('change', () => {
        stop();
        choose(Number(yearChoice.value), { fromList: true });
    });

    // For the map's AI (map-ask.js): the view shown, as MapView has it, and a view to go to, each part as its own
    // control would set it, leaving out any part not given (null). Police districts given are ringed and numbered,
    // and the map goes to them: into the first's popup if it's one, or framing them all.
    window.crimeMap = {
        ready: figuresShown,
        now: () => ({ year: shownYear, region: selected ?? 'Malaysia', shade: measure, pins: sizing ?? '', raised }),
        go: async ({ view = {}, districts = [], play: playIt = false }) => {
            await figuresShown;
            stop();

            if (view.shade && rates?.measures?.[view.shade] && view.shade !== measure) {
                measure = view.shade;
                if (measureChoice) {
                    measureChoice.value = measure;
                }
            }
            if (view.pins !== null && view.pins !== undefined && (view.pins || null) !== sizing) {
                sizing = view.pins || null;
                if (pinsChoice) {
                    pinsChoice.value = view.pins;
                }
                tip.remove();
            }
            if (view.year && years.includes(view.year) && view.year !== shownYear) {
                await choose(view.year);
            } else {
                paint();
                sizeBubbles();
                describe(selected);
            }

            const picks = districts.map((key) => districtsByKey.get(key)).filter(Boolean);
            if (typeof view.raised === 'boolean' && view.raised !== raised && raiseButton) {
                raise(view.raised);
            }
            if (view.region) {
                select(view.region === 'Malaysia' ? null : view.region, { zoom: picks.length === 0 });
            }

            map.getSource('ai-picks')?.setData({
                type: 'FeatureCollection',
                features: picks.map((district, at) => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [district.lng, district.lat] }, properties: { number: String(at + 1) } })),
            });
            if (picks.length === 1) {
                openPin(`${picks[0].region}|${picks[0].name}`);
            } else if (picks.length > 1) {
                const lngs = picks.map((district) => district.lng);
                const lats = picks.map((district) => district.lat);
                frame([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], 10, 900);
            }

            if (playIt && years.length > 1) {
                play();
            }
        },
        // The ringed police districts gone, as the map's AI's answer is closed.
        clearPicks: () => map.getSource('ai-picks')?.setData({ type: 'FeatureCollection', features: [] }),
    };
    document.dispatchEvent(new Event('crime-map'));

    load(yearChoice?.value);
})();
