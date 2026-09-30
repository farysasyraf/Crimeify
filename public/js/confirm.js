// Asks before a form adds, changes or deletes something, with the same SweetAlert2 box as AMV's "Kepastian!":
// a question icon to save, a warning to update and an error to delete, with No and Yes buttons, Yes on the right.
// A form opts in with data-confirm="the question" and data-confirm-kind="save", "update" or "delete".
// A form with data-confirm-image (a photo's address, set by profile-photo.js) shows that photo instead of the icon.
// Without JavaScript, or if SweetAlert2 didn't load, forms submit straight away.
(() => {
    const icons = { save: 'question', update: 'warning', delete: 'error' };

    const confirmed = (question, kind, image) => Swal.fire({
        title: 'Please confirm',
        text: question,
        icon: image ? undefined : (icons[kind] ?? 'question'),
        imageUrl: image,
        imageWidth: 160,
        imageHeight: 160,
        imageAlt: 'The chosen photo',
        // The app is dark whatever the system's theme; site.css makes the box one of its charcoal panels.
        theme: 'dark',
        showCancelButton: true,
        reverseButtons: true,
        buttonsStyling: false,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No',
        // A delete can't be undone, so No has the focus there.
        focusCancel: kind === 'delete',
        customClass: { popup: 'swal-panel', actions: 'swal-actions', confirmButton: 'btn swal-yes', cancelButton: 'btn swal-no', image: 'swal-photo' },
    }).then((result) => {
        // No, Esc or a click beside the box: say so, as AMV's "Tindakan dibatalkan." (toast.js).
        if (!result.isConfirmed) {
            window.toast?.('info', 'Cancelled. Nothing was changed.');
        }

        return result.isConfirmed;
    });

    // Runs once the browser's own checks on the form have passed.
    document.addEventListener('submit', async (event) => {
        const form = event.target;

        if (!form.dataset.confirm || form.dataset.confirmed || typeof Swal === 'undefined') {
            return;
        }

        event.preventDefault();

        if (await confirmed(form.dataset.confirm, form.dataset.confirmKind, form.dataset.confirmImage)) {
            // Submit again the same way, past this check; the flag only lasts while the submit is sent.
            form.dataset.confirmed = 'true';
            form.requestSubmit(event.submitter);
            delete form.dataset.confirmed;
        }
    });

    // A link that deletes through a separate form, like Delete on the Routes page. Without JavaScript it opens
    // the record's delete page instead. Links that share one form, like the Crime data list's, give the address to
    // send it to with data-confirm-action.
    document.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-confirm-form]');

        if (!link || typeof Swal === 'undefined') {
            return;
        }

        event.preventDefault();

        if (await confirmed(link.dataset.confirm, link.dataset.confirmKind)) {
            const form = document.getElementById(link.dataset.confirmForm);

            if (form && link.dataset.confirmAction) {
                form.action = link.dataset.confirmAction;
            }
            form?.requestSubmit();
        }
    });
})();
