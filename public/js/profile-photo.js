// Upload new photo on My profile and Edit user: choosing a photo uploads it, after the "Please confirm" box
// (confirm.js) shows it round, as it will look. Without JavaScript, the file box and an Upload button show instead.
(() => {
    document.addEventListener('change', (event) => {
        const input = event.target.closest('.photo-input');
        const file = input?.files?.[0];

        if (!file) {
            return;
        }

        const form = input.form;

        if (form.dataset.confirmImage) {
            URL.revokeObjectURL(form.dataset.confirmImage);
        }
        form.dataset.confirmImage = URL.createObjectURL(file);

        form.requestSubmit();
    });

    // Opening the file box again forgets the last choice, so choosing the same photo after answering No still asks.
    document.addEventListener('click', (event) => {
        const input = event.target.closest('.photo-input');

        if (input) {
            input.value = '';
        }
    });
})();
