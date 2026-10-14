// DistrictGuessr's map, for the solo game (districtguessr.js) and live games, on the phones (live.js) and the big screen
// (live-host.js): Malaysia's states, dark, framed, and nothing else (Leaflet, without a street map, so no town's name
// gives the district away), and its pins.
window.GuessrMap = (() => {
    const number = new Intl.NumberFormat('en-MY');

    return {
        // The states' outlines, fetched once, as the page loads, so the map is ready when the game starts. None when they
        // can't be had: the map is drawn without them.
        load: (url) => fetch(url).then((response) => response.json()).catch(() => null),

        // The map in the element given, once the outlines are in: Leaflet's map, and Malaysia's bounds to frame it with.
        async draw(element, outlines) {
            const map = L.map(element, { minZoom: 4, maxZoom: 11, zoomSnap: 0.5, boxZoom: false });
            map.attributionControl.setPrefix(false);
            map.attributionControl.addAttribution(
                `${t('Boundaries')}: <a href="https://www.geoboundaries.org">geoBoundaries</a> · © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>`,
            );

            const states = await outlines;
            const shapes = L.geoJSON(states ?? { type: 'FeatureCollection', features: [] }, {
                interactive: false,
                style: { color: '#3aaaf2', weight: 1, opacity: 0.55, fillColor: '#1a2028', fillOpacity: 1 },
            }).addTo(map);

            const malaysia = states ? shapes.getBounds() : L.latLngBounds([0.85, 99.6], [7.4, 119.3]);
            map.setMaxBounds(malaysia.pad(0.5));

            return { map, malaysia };
        },

        // A pin: the player's ('guess'), blue, or the district's ('answer'), green, its point at the place.
        pin: (kind) => L.divIcon({
            className: `guessr-pin guessr-pin-${kind}`,
            html: '<span class="material-icon" aria-hidden="true">location_on</span>',
            iconSize: [40, 40],
            iconAnchor: [20, 38],
        }),

        // The dashed line from a pin to the district.
        line: (from, to) => L.polyline([from, to], { color: '#eef2f6', weight: 2, dashArray: '6 8', interactive: false }),

        // Kilometres as the game shows them: to a tenth under 10.
        km: (km) => (km < 10 ? km.toFixed(1) : number.format(Math.round(km))),
    };
})();
