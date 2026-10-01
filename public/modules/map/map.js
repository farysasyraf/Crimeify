// Map page: Malaysia's states and federal territories on a Leaflet map, from the boundaries file in
// #state-map's data-boundaries. Pointing at a region shows its name; clicking it, or its button in the list,
// selects it and shows its details. With data-crime, each police district also gets a pin whose popup gives its
// crime figures for the year chosen in the panel, and the panel gives the country's or the chosen region's totals.
// The street map underneath comes from OpenStreetMap, so it needs the internet; the regions, pins and figures
// come from this app, so they show without it.
(() => {
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

        // And as an error toast, where the page has them (toast.js, which runs after this file; not the public map).
        if (window.toast) {
            window.toast('error', text);
        } else {
            window.addEventListener('DOMContentLoaded', () => window.toast?.('error', text), { once: true });
        }
    };

    if (typeof L === 'undefined') {
        showStatus(t("The map couldn't load. Reload the page to try again."));
        return;
    }

    const details = document.querySelector('[data-state-details]');
    const showAll = document.querySelector('[data-show-all]');
    const yearChoice = document.querySelector('[data-crime-year]');
    const choices = new Map([...document.querySelectorAll('[data-state]')].map((button) => [button.dataset.state, button]));

    // The search over the map works with this script, so it shows once the script runs (set up further down).
    const search = document.querySelector('[data-map-search]');
    if (search) {
        search.hidden = false;
    }

    // The app's blues: regions lightly shaded in the soft blue, and the one selected filled with the bright blue the
    // list and the menu mark the current choice with, outlined darker so it stands out on the street map.
    const style = {
        normal: { color: '#3d8fc9', weight: 1.5, fillColor: '#62a9dc', fillOpacity: 0.14 },
        hover: { weight: 2.5, fillOpacity: 0.3 },
        selected: { color: '#1b6fae', weight: 3, fillColor: '#3aaaf2', fillOpacity: 0.5 },
    };

    // Malaysia's two halves, Peninsular and Borneo, with room around them.
    const malaysia = L.latLngBounds([0.8, 99.6], [7.4, 119.3]);

    const map = L.map(container, {
        // Low enough for all of Malaysia to fit on a phone.
        minZoom: 4,
        // Whole zoom levels only: in between, the street map's tiles are stretched and show thin seams.
        zoomSnap: 1,
        // Near Malaysia, but with room enough to frame it beside the cards over the map.
        maxBounds: malaysia.pad(1.5),
        maxBoundsViscosity: 0.8,
        // At the bottom right instead (below), clear of the cards over the map.
        zoomControl: false,
    });

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // What the cards over the map cover, as padding to fit Malaysia, a region or a popup into the rest of it: the
    // search at the top and the column down the left (data-map-cover), each as far as it reaches in. Under the map, on
    // a narrow screen, or with the map enlarged, they cover none of it, but the search, which stays on it.
    const covers = [...document.querySelectorAll('[data-map-cover]')];
    const uncovered = () => {
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

        return { paddingTopLeft: [clear.left, clear.top], paddingBottomRight: [clear.right, clear.bottom] };
    };

    map.fitBounds(malaysia, uncovered());

    // The sidebar narrowing, or the window resizing, changes the map's size; Leaflet has to be told.
    new ResizeObserver(() => map.invalidateSize()).observe(container);

    const layers = new Map();
    let selected = null;

    // One year's figures from MapController@crime, once they've loaded.
    let crime = null;

    const count = new Intl.NumberFormat('en-MY');

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

    // A police district's popup: each category's total and how it compares with the year before, then the
    // number of each crime type in it.
    const popup = (district) => {
        const content = element('div', undefined, 'crime-popup');
        const region = choices.get(district.region)?.dataset.name ?? district.region;

        content.append(
            element('h3', district.name),
            element('p', t('Police district in :region · :year', { region, year: crime.year }), 'crime-popup-place'),
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
        const covers = () => {
            if (!button) {
                return t(crime.byState ? 'All states · :year' : 'All police districts · :year', { year: crime.year });
            }
            if (crime.byState) {
                const includes = place.includes?.length ? [t('with :regions', { regions: place.includes.join(` ${t('and')} `) })] : [];
                return [button.dataset.kind, crime.year, ...includes].join(' · ');
            }
            return [button.dataset.kind, tn(place.districts, ':count police district', ':count police districts'), crime.year].join(' · ');
        };

        details.append(element('p', covers(), 'muted'), totals(place), element('p', hint, 'muted crime-hint'));
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

    const select = (code, { zoom = false } = {}) => {
        if (selected) {
            layers.get(selected)?.setStyle(style.normal);
            choices.get(selected)?.setAttribute('aria-pressed', 'false');
        }

        selected = code;
        const layer = code ? layers.get(code) : null;

        if (layer) {
            layer.setStyle(style.selected).bringToFront();
            choices.get(code)?.setAttribute('aria-pressed', 'true');
            if (zoom) {
                map.flyToBounds(layer.getBounds(), { ...uncovered(), maxZoom: 9, duration: 0.6 });
            }
        } else if (zoom) {
            map.flyToBounds(malaysia, { ...uncovered(), duration: 0.6 });
        }

        showAll.hidden = !layer;
        describe(layer ? code : null);
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

    let toggle;

    const labelToggle = (enlarged) => {
        const text = t(enlarged ? 'Make the map smaller' : 'Enlarge the map');
        toggle.setAttribute('aria-label', text);
        toggle.title = text;
        toggle.firstChild.textContent = enlarged ? 'close_fullscreen' : 'open_in_full';
    };

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
        map.invalidateSize();
    };

    dialog.addEventListener('close', () => {
        if (dialog.open) {
            return;
        }
        putBack();
        labelToggle(false);
        toggle.focus();
        map.invalidateSize();
    });

    // Added after the zoom buttons, so over them: Leaflet stacks a bottom corner's controls upwards.
    const control = L.control({ position: 'bottomright' });
    control.onAdd = () => {
        const bar = L.DomUtil.create('div', 'leaflet-bar map-enlarge');
        toggle = L.DomUtil.create('button', '', bar);
        toggle.type = 'button';
        toggle.append(element('span', '', 'material-icon'));
        toggle.firstChild.setAttribute('aria-hidden', 'true');
        labelToggle(false);
        L.DomEvent.disableClickPropagation(bar);
        L.DomEvent.on(toggle, 'click', () => (dialog.open ? dialog.close() : enlarge()));
        return bar;
    };
    control.addTo(map);

    // Only a click that starts and ends on the dimmed page counts, not a drag of the map let go outside it.
    let pressedOutside = false;
    dialog.addEventListener('pointerdown', (event) => { pressedOutside = event.target === dialog; });
    dialog.addEventListener('click', (event) => {
        if (pressedOutside && event.target === dialog) {
            dialog.close();
        }
    });

    // Escape on the map closes an open popup first, as Leaflet does, and only then makes the map small.
    let popupOpen = false;
    let escapeClosesPopup = false;
    map.on({ popupopen: () => { popupOpen = true; }, popupclose: () => { popupOpen = false; } });
    dialog.addEventListener('keydown', (event) => {
        escapeClosesPopup = event.key === 'Escape' && popupOpen && document.activeElement === container;
    }, true);
    dialog.addEventListener('cancel', (event) => {
        if (escapeClosesPopup) {
            event.preventDefault();
            escapeClosesPopup = false;
        }
    });

    fetch(container.dataset.boundaries)
        .then((response) => {
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            return response.json();
        })
        .then((boundaries) => {
            L.geoJSON(boundaries, {
                style: () => style.normal,
                onEachFeature: (feature, layer) => {
                    const code = feature.properties.shapeISO;
                    const name = choices.get(code)?.dataset.name ?? feature.properties.shapeName;

                    layers.set(code, layer);
                    layer.bindTooltip(name, { sticky: true, direction: 'top', offset: [0, -8] });
                    layer.on({
                        mouseover: () => code !== selected && layer.setStyle(style.hover),
                        mouseout: () => code !== selected && layer.setStyle(style.normal),
                        click: () => select(code),
                    });
                },
                attribution: `${t('Boundaries')}: <a href="https://www.geoboundaries.org">geoBoundaries</a>`,
            }).addTo(map);
        })
        .catch(() => showStatus(t("The states' boundaries couldn't load. Reload the page to try again.")));

    for (const [code, button] of choices) {
        button.addEventListener('click', () => select(code === selected ? null : code, { zoom: true }));
    }

    showAll.addEventListener('click', () => select(null, { zoom: true }));

    // The police districts' pins once they load, by "region|name", and how to open one, which needs them (below).
    const pinsByKey = new Map();
    let openPin = () => false;

    // A pin's popup opens clear of the cards over the map, wherever they are by then.
    const padded = (marker) => {
        const clear = uncovered();
        Object.assign(marker.getPopup().options, { autoPanPaddingTopLeft: clear.paddingTopLeft, autoPanPaddingBottomRight: clear.paddingBottomRight });
        return marker;
    };

    // The map to a police district's pin, clear of the cards, without opening it. False if there's no such pin.
    const goToPin = (key) => {
        const marker = pinsByKey.get(key);
        if (!marker) {
            return false;
        }
        const at = marker.getLatLng();
        map.flyToBounds(L.latLngBounds(at, at), { ...uncovered(), maxZoom: 11, duration: 0.6 });
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

    const go = (place) => {
        if (place.kind === 'state') {
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
            input.value = place.kind === 'state' ? place.name : `${place.name}, ${regionName(place.region)}`;
            none.textContent = '';
            close();
            go(place);
        };

        const suggest = () => {
            found = find(input.value);
            list.replaceChildren(...found.map((place, index) => {
                const option = element('li');
                option.id = `map-search-option-${index}`;
                option.setAttribute('role', 'option');
                option.append(element('span', place.name, 'map-search-name'), element('span', place.about, 'map-search-kind'));
                option.addEventListener('click', () => pick(place));
                return option;
            }));
            list.hidden = found.length === 0;
            input.setAttribute('aria-expanded', String(found.length > 0));
            none.textContent = found.length > 0 ? '' : t('No matches for “:text”', { text: input.value.trim() });
            mark(-1);
        };

        input.addEventListener('input', () => {
            if (input.value.trim()) {
                suggest();
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

    if (!container.dataset.crime) {
        return;
    }

    // Pins close together are grouped into a numbered circle until zoomed in: Kuala Lumpur's police districts are
    // only a few kilometres apart. Without the cluster plugin every pin shows on its own.
    const pins = L.markerClusterGroup
        ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45, spiderfyOnMaxZoom: true })
        : L.layerGroup();
    pins.addTo(map);

    // A police district's pin opened, as the search does: zoomed in until it's out of its group, if it's in one.
    openPin = (key) => {
        const marker = pinsByKey.get(key);
        if (!marker) {
            return false;
        }
        const reveal = () => padded(marker).openPopup();
        pins.zoomToShowLayer ? pins.zoomToShowLayer(marker, reveal) : reveal();
        return true;
    };

    let open = null;
    let request = null;
    // The credit on the map for the figures shown.
    let credited = null;

    const show = (figures) => {
        // A popup open when the year changes opens again with the new year's figures.
        const reopen = open;
        crime = figures;
        pins.clearLayers();
        pinsByKey.clear();

        if (status.dataset.part === 'crime') {
            status.hidden = true;
        }

        const markers = figures.districts.map((district) => {
            const key = `${district.region}|${district.name}`;
            const marker = L.marker([district.lat, district.lng], { title: district.name, alt: t(':name police district', { name: district.name }) });

            // Before Leaflet opens the popup, clicked or with Enter, so it opens clear of the cards over the map.
            marker.on('click keypress', () => padded(marker));
            marker.bindPopup(() => popup(district), {
                maxWidth: 300,
                minWidth: 240,
                className: 'crime-popup-shell',
            });
            marker.on({
                popupopen: () => { open = key; },
                popupclose: () => { if (open === key) open = null; },
            });
            marker.key = key;
            pinsByKey.set(key, marker);
            return marker;
        });

        if (pins.addLayers) {
            pins.addLayers(markers);
        } else {
            markers.forEach((marker) => pins.addLayer(marker));
        }

        if (reopen) {
            openPin(reopen);
        }

        // The figures' source: data.gov.my's, linked, or for a year by state, the police's crime index.
        let source = element(figures.about ? 'a' : 'span', figures.credit);
        if (figures.about) {
            source.href = figures.about;
        }
        source = source.outerHTML;
        if (source !== credited) {
            if (credited) {
                map.attributionControl.removeAttribution(credited);
            }
            map.attributionControl.addAttribution(source);
            credited = source;
        }

        describe(selected);
    };

    const load = (year) => {
        request?.abort();
        request = new AbortController();

        const url = new URL(container.dataset.crime, window.location.href);
        if (year) {
            url.searchParams.set('year', year);
        }

        fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(response.statusText);
                }
                return response.json();
            })
            .then(show)
            .catch((problem) => {
                if (problem.name !== 'AbortError') {
                    showStatus(t("The crime figures couldn't load. Choose the year again, or reload the page."), 'crime');
                }
            });
    };

    yearChoice?.addEventListener('change', () => load(yearChoice.value));
    load(yearChoice?.value);
})();
