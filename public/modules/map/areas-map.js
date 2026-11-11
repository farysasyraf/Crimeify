// "Or pick them on the map", on Check an area: every police district's pin on the Map page's dark street map, drawn by
// MapLibre GL JS, which loads only as the card is scrolled to. Clicking a pin puts it in the next empty list of the
// picker above (_picker), or with three chosen, says to take one out first; clicking a chosen one takes it out. Chosen
// pins are ringed and numbered in their area's colour, blue, orange and teal, as the areas side by side have them.
// Pins close together are grouped into numbered circles, which open up to their pins. The pins come from "Near you"
// (data-district-pins); without them, or without WebGL, the card stays hidden and the lists are all there is.
(async () => {
    const card = document.querySelector('[data-pick-map]');
    const pinList = document.querySelector('[data-district-pins]');
    const lists = [...document.querySelectorAll('.area-picker select[name="areas[]"]')];

    if (!card || !pinList || lists.length === 0 || !('IntersectionObserver' in window)) {
        return;
    }

    const pins = JSON.parse(pinList.textContent);
    const say = (text) => { card.querySelector('[data-pick-say]').textContent = text; };
    const colours = ['#3987e5', '#d95926', '#199e70'];
    const malaysia = [[99.6, 0.8], [119.3, 7.4]];
    const empty = { type: 'FeatureCollection', features: [] };
    card.hidden = false;

    // Not until the card is near the screen.
    await new Promise((resolve) => {
        const watch = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                watch.disconnect();
                resolve();
            }
        }, { rootMargin: '200px' });
        watch.observe(card);
    });

    let maplibregl;
    let style;
    try {
        maplibregl = await import(card.dataset.maplibre);
        const response = await fetch(card.dataset.style);
        style = response.ok ? await response.json() : null;
    } catch {
        style = null;
    }
    if (!maplibregl) {
        card.hidden = true;
        return;
    }

    let map;
    try {
        map = new maplibregl.Map({
            container: card.querySelector('[data-pick-canvas]'),
            // Without the street map, a plain dark one, so the pins still show.
            style: style ?? { version: 8, glyphs: 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf', sources: {}, layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#121820' } }] },
            bounds: malaysia,
            fitBoundsOptions: { padding: 20 },
            // The page scrolls over it; Ctrl and the wheel, or two fingers, move the map.
            cooperativeGestures: true,
            attributionControl: { compact: true },
            locale: {
                'NavigationControl.ZoomIn': t('Zoom in'),
                'NavigationControl.ZoomOut': t('Zoom out'),
                'CooperativeGesturesHandler.WindowsHelpText': t('Use Ctrl and scroll to zoom the map'),
                'CooperativeGesturesHandler.MacHelpText': t('Use ⌘ and scroll to zoom the map'),
                'CooperativeGesturesHandler.MobileHelpText': t('Use two fingers to move the map'),
            },
        });
    } catch {
        card.hidden = true;
        return;
    }
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

    // What the lists have chosen, by path, in order.
    const chosen = () => lists.map((list) => list.value);

    // A list set as if chosen by hand: Select2 shows it, and anything listening hears.
    const choose = (list, value) => {
        list.value = value;
        if (window.jQuery?.fn.select2) {
            window.jQuery(list).trigger('change');
        } else {
            list.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const ring = () => map.getSource('chosen')?.setData({
        type: 'FeatureCollection',
        features: chosen().flatMap((path, at) => {
            const pin = pins.find((each) => each.path === path);
            return pin ? [{ type: 'Feature', geometry: { type: 'Point', coordinates: [pin.lng, pin.lat] }, properties: { number: String(at + 1), colour: colours[at] } }] : [];
        }),
    });

    for (const list of lists) {
        list.addEventListener('change', ring);
        window.jQuery?.(list).on('change', ring);
    }

    const tip = new maplibregl.Popup({ closeButton: false, closeOnClick: false, className: 'area-map-tip', offset: 12 });

    map.on('load', () => {
        map.addSource('pins', {
            type: 'geojson',
            cluster: true, clusterRadius: 40, clusterMaxZoom: 9,
            data: { type: 'FeatureCollection', features: pins.map((pin) => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [pin.lng, pin.lat] }, properties: { path: pin.path, name: pin.name, region: pin.region } })) },
        });
        map.addSource('chosen', { type: 'geojson', data: empty });

        const grouped = ['has', 'point_count'];
        map.addLayer({
            id: 'groups', type: 'circle', source: 'pins', filter: grouped,
            paint: { 'circle-color': '#3aaaf2', 'circle-radius': ['step', ['get', 'point_count'], 13, 5, 16, 15, 20], 'circle-stroke-width': 5, 'circle-stroke-color': 'rgba(58, 170, 242, 0.3)' },
        });
        map.addLayer({
            id: 'group-count', type: 'symbol', source: 'pins', filter: grouped,
            layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': ['Noto Sans Bold'], 'text-size': 12, 'text-allow-overlap': true },
            paint: { 'text-color': '#0b1a26' },
        });
        map.addLayer({
            id: 'pins', type: 'circle', source: 'pins', filter: ['!', grouped],
            paint: { 'circle-color': '#3aaaf2', 'circle-radius': 6, 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff' },
        });
        // Each chosen pin ringed and numbered in its area's colour.
        map.addLayer({
            id: 'chosen-ring', type: 'circle', source: 'chosen',
            paint: { 'circle-color': ['get', 'colour'], 'circle-radius': 11, 'circle-stroke-width': 3, 'circle-stroke-color': '#ffffff' },
        });
        map.addLayer({
            id: 'chosen-number', type: 'symbol', source: 'chosen',
            layout: { 'text-field': ['get', 'number'], 'text-font': ['Noto Sans Bold'], 'text-size': 12, 'text-allow-overlap': true },
            paint: { 'text-color': '#ffffff' },
        });
        ring();

        map.on('click', 'groups', async (event) => {
            const group = event.features[0];
            const zoom = await map.getSource('pins').getClusterExpansionZoom(group.properties.cluster_id);
            map.easeTo({ center: group.geometry.coordinates, zoom });
        });

        map.on('click', 'pins', (event) => {
            const { path, name } = event.features[0].properties;
            const at = chosen().indexOf(path);

            if (at >= 0) {
                choose(lists[at], '');
                say(t(':name taken out.', { name }));
                return;
            }
            const free = lists.find((list) => !list.value);
            if (!free) {
                say(t('Three are chosen already: click one to take it out first.'));
                return;
            }
            choose(free, path);
            say(t(':name added. Choose Check to see it.', { name }));
        });

        for (const layer of ['pins', 'groups']) {
            map.on('mouseenter', layer, () => { map.getCanvas().style.cursor = 'pointer'; });
            map.on('mouseleave', layer, () => {
                map.getCanvas().style.cursor = '';
                tip.remove();
            });
        }
        map.on('mousemove', 'pins', (event) => {
            const pin = event.features[0];
            const name = document.createElement('strong');
            name.textContent = pin.properties.name;
            tip.setLngLat(pin.geometry.coordinates).setDOMContent(name).addTo(map);
        });
    });
})();
