// Police stations page: as on the Crime data page, choosing a state or how many rows a page shows, or ticking
// "Only those missing details", shows the list straight away, refreshed in place (live-table.js), and so does choosing
// how many rows the update log shows. The search waits for Enter or Show, so typing doesn't.
(() => {
    document.addEventListener('change', (event) => {
        const filters = event.target.closest('[data-station-filters]');
        if (filters && event.target.type !== 'search') {
            filters.requestSubmit();
        }
    });
})();
