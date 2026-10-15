// "Near you", on the Map page (map.js) and Check an area: the police districts nearest the visitor, and the state
// they're in, worked out in their browser with Turf.js (public/vendor/turf), so where they are is never sent to the
// server or kept. Distances are in a straight line to each district's pin, which is mostly its police headquarters:
// police districts' boundaries aren't published, so the nearest pin may be a neighbouring district's near a border.
// Turf is large, so it loads the first time it's needed, which most visits never are.
(() => {
    let loading = null;

    const whole = new Intl.NumberFormat('en-MY', { maximumFractionDigits: 0 });
    const tenths = new Intl.NumberFormat('en-MY', { maximumFractionDigits: 1 });

    window.CrimeifyNearby = {
        // Turf, from the address given, loaded once however often it's asked for.
        load(url) {
            if (window.turf) {
                return Promise.resolve(window.turf);
            }
            loading ??= new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = url;
                script.onload = () => (window.turf ? resolve(window.turf) : reject(new Error('Turf.js did not load')));
                script.onerror = () => {
                    loading = null;
                    reject(new Error('Turf.js did not load'));
                };
                document.head.append(script);
            });
            return loading;
        },

        // The pins ({lat, lng, …}) nearest a point, closest first, each with its distance in kilometres.
        nearest(turf, pins, lat, lng, count = 3) {
            const here = turf.point([lng, lat]);
            return pins
                .map((pin) => ({ ...pin, km: turf.distance(here, turf.point([pin.lng, pin.lat]), { units: 'kilometers' }) }))
                .sort((a, b) => a.km - b.km)
                .slice(0, count);
        },

        // The feature of a GeoJSON collection, like the states' boundaries, that a point is in, if any.
        within(turf, collection, lat, lng) {
            const here = turf.point([lng, lat]);
            return collection.features.find((feature) => turf.booleanPointInPolygon(here, feature)) ?? null;
        },

        // "350 m", "2.3 km", "12 km".
        distance(km) {
            if (km < 1) {
                return `${whole.format(Math.max(10, Math.round(km * 100) * 10))} m`;
            }
            return `${(km < 10 ? tenths : whole).format(km)} km`;
        },

        // Why the browser couldn't give a location, in words: refused, not secure, unavailable, or too slow.
        problem(error) {
            if (!window.isSecureContext) {
                return t('Your location can only be used on a secure (https) page.');
            }
            return {
                1: t("This site isn't allowed your location. Allow it in your browser's settings to use this."),
                3: t('Finding your location took too long. Try again.'),
            }[error?.code] ?? t("Your location isn't available just now. Try again in a moment.");
        },
    };
})();
