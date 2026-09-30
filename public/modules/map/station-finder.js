// "Find a police station" under the map (map::index): choosing a state lists its police stations in the second
// list, and choosing a station shows its police district, address and phone number under the lists. The stations
// come with the page (MapController@stations), by state. Both lists are Select2 boxes (select2-init.js), which send
// the usual change event.
// The address is a link to the station on Google Maps, in a new tab. On a phone, which may have the map apps, it asks
// first: Google Maps or Waze. Each opens its app if the phone has it, and its website if not. Without SweetAlert2,
// it's always Google Maps.
(() => {
    const data = document.querySelector('[data-station-list]');
    const stateChoice = document.querySelector('[data-station-state]');
    const stationChoice = document.querySelector('[data-station-choice]');
    const hint = document.querySelector('[data-station-hint]');
    const details = document.querySelector('[data-station-details]');

    if (!data || !stateChoice || !stationChoice || !details) {
        return;
    }

    const stations = JSON.parse(data.textContent);

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

    // Select2 draws the list again from its options.
    const redraw = (select) => window.jQuery?.fn.select2 && window.jQuery(select).trigger('change.select2');

    // A phone or tablet, where Google Maps and Waze are apps: Android, iPhone, and iPad, which says it's a Mac but
    // has a touch screen.
    const onPhone = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
        || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

    // Google Maps or Waze, in the app's charcoal box, the apps as its bright blue buttons, one under the other.
    const chooseApp = (station) => Swal.fire({
        title: t('Open in'),
        text: station.name,
        theme: 'dark',
        showDenyButton: true,
        showCancelButton: true,
        buttonsStyling: false,
        confirmButtonText: 'Google Maps',
        denyButtonText: 'Waze',
        cancelButtonText: t('Cancel'),
        customClass: { popup: 'swal-panel', actions: 'swal-actions swal-apps', confirmButton: 'btn swal-app', denyButton: 'btn swal-app', cancelButton: 'btn swal-app-cancel' },
    }).then((result) => {
        // In the same tab, which lets the phone hand the address to the app.
        if (result.isConfirmed) {
            window.location.href = station.maps;
        } else if (result.isDenied) {
            window.location.href = station.waze;
        }
    });

    // The station on the map: its address, or without one, a search for its name.
    const mapLink = (station, text) => {
        const link = element('a', undefined, 'station-map-link');
        const pin = element('span', 'location_on', 'material-icon');
        pin.setAttribute('aria-hidden', 'true');
        link.append(pin, element('span', text));
        link.href = station.maps;
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = t(onPhone ? 'Open in Google Maps or Waze' : 'Open in Google Maps, in a new tab');

        link.addEventListener('click', (event) => {
            if (onPhone && typeof Swal !== 'undefined') {
                event.preventDefault();
                chooseApp(station);
            }
        });

        return link;
    };

    const stateName = () => stateChoice.selectedOptions[0]?.textContent.trim() ?? '';

    const showStation = () => {
        const station = (stations[stateChoice.value] ?? []).find((each) => String(each.id) === stationChoice.value);
        details.replaceChildren();
        details.hidden = !station;

        if (!station) {
            return;
        }

        const facts = element('dl', undefined, 'station-facts');
        const fact = (label, value) => {
            const row = element('div');
            row.append(element('dt', label), value);
            facts.append(row);
        };
        const missing = () => element('span', t('Not added yet'), 'muted');

        const address = element('dd');
        if (station.address) {
            address.append(mapLink(station, station.address));
        } else {
            address.append(missing(), element('br'), mapLink(station, t('Search for it on the map')));
        }
        address.append(element('span', t(onPhone ? 'Opens Google Maps or Waze' : 'Opens Google Maps'), 'station-map-hint muted'));
        fact(t('Address'), address);

        const phone = element('dd');
        if (station.phone && station.call) {
            const link = element('a', station.phone);
            link.href = station.call;
            phone.append(link);
        } else {
            phone.append(station.phone ? element('span', station.phone) : missing());
        }
        fact(t('Phone'), phone);

        details.append(
            element('h3', station.name),
            element('p', t(':district police district · :state', { district: station.district, state: stateName() }), 'muted station-place'),
            facts,
        );
    };

    const listStations = () => {
        const inState = stations[stateChoice.value] ?? [];

        stationChoice.replaceChildren(element('option', ''));
        for (const station of inState) {
            const option = element('option', station.name);
            option.value = String(station.id);
            stationChoice.append(option);
        }

        stationChoice.disabled = inState.length === 0;
        hint.textContent = inState.length === 0
            ? t('Choose a state first.')
            : tn(inState.length, ':count police station in :state.', ':count police stations in :state.', { state: stateName() });
        redraw(stationChoice);
        showStation();
    };

    stateChoice.addEventListener('change', listStations);
    stationChoice.addEventListener('change', showStation);

    // Back to the page with a state still chosen, as browsers keep a form's choices.
    if (stateChoice.value) {
        listStations();
    }
})();
