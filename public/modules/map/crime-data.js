// Crime data page: marks the counts changed but not yet saved and says how many there are, asks before leaving the
// page or showing other figures with any, sends only the changed ones when saving, and shows the figures (and how
// many a page) chosen in the filters, and the update log with how many rows a page, as soon as they're chosen.
// The lists refresh in place (live-table.js), so everything here finds the list as it is now.
(() => {
    const counts = () => document.querySelector('[data-crime-counts]');
    let saving = false;

    const changedInputs = () => [...(counts()?.querySelectorAll('.crime-count') ?? [])]
        .filter((input) => input.value !== input.previousElementSibling.value);

    const showUnsaved = () => {
        const form = counts();
        if (!form) {
            return;
        }
        const changed = changedInputs();
        form.querySelectorAll('.crime-count').forEach((input) => input.classList.toggle('is-changed', changed.includes(input)));
        form.querySelector('[data-unsaved]').textContent = changed.length === 0
            ? ''
            : `${changed.length} unsaved ${changed.length === 1 ? 'change' : 'changes'}`;
    };

    document.addEventListener('input', (event) => {
        if (event.target.closest('[data-crime-counts]')) {
            showUnsaved();
        }
    });
    document.addEventListener('live:updated', showUnsaved);
    showUnsaved();

    // Saving leaves the page without asking. The confirmation box stops the first submit while it asks, so only
    // one that goes ahead counts; this runs after it, on the window.
    window.addEventListener('submit', (event) => {
        const form = counts();
        if (event.target !== form || event.defaultPrevented) {
            return;
        }
        saving = true;

        // Only the changed counts, with what they were: PHP takes only so many values from a form, which a long list,
        // like All, would pass. The rest are left out, and the row count says how many are sent.
        const changed = changedInputs();
        form.querySelectorAll('.crime-count').forEach((input) => {
            const leaveOut = !changed.includes(input);
            input.disabled = leaveOut;
            input.previousElementSibling.disabled = leaveOut;
        });
        form.querySelector('[data-rows]').value = changed.length;
    });

    // Coming back to the page with the Back button shows it as it was left, so bring the counts back.
    window.addEventListener('pageshow', (event) => {
        const form = counts();
        if (event.persisted && form) {
            saving = false;
            form.querySelectorAll('input:disabled').forEach((input) => {
                input.disabled = false;
            });
            form.querySelector('[data-rows]').value = form.querySelectorAll('.crime-count').length;
        }
    });

    // Leaving the page would lose the changes, so the browser asks first.
    window.addEventListener('beforeunload', (event) => {
        if (!saving && changedInputs().length > 0) {
            event.preventDefault();
        }
    });

    // Showing other figures in place would lose them too, so the page asks, in its confirmation box's style.
    document.addEventListener('live:leave', (event) => {
        if (!event.target.contains(counts()) || changedInputs().length === 0) {
            return;
        }
        const count = changedInputs().length;
        const question = count === 1
            ? "1 changed count isn't saved yet. Show other figures anyway, and lose it?"
            : `${count} changed counts aren't saved yet. Show other figures anyway, and lose them?`;

        event.detail.asks.push(typeof Swal === 'undefined'
            ? Promise.resolve(window.confirm(question))
            : Swal.fire({
                title: 'Unsaved changes',
                text: question,
                icon: 'warning',
                theme: 'dark',
                showCancelButton: true,
                reverseButtons: true,
                focusCancel: true,
                buttonsStyling: false,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
                customClass: { popup: 'swal-panel', actions: 'swal-actions', confirmButton: 'btn swal-yes', cancelButton: 'btn swal-no' },
            }).then((result) => result.isConfirmed));
    });

    // The figures' filters and the update log's rows per page: choosing shows them straight away.
    document.addEventListener('change', (event) => {
        const filters = event.target.closest('[data-crime-filters]');
        if (!filters) {
            return;
        }
        // Another state's districts differ, so its district choice starts again.
        if (event.target.name === 'state' && filters.elements.district) {
            filters.elements.district.value = '';
        }
        filters.requestSubmit();
    });
})();
