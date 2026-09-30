// Manage menu form: show the Material icon named in the Icon box while it's typed.
// Without JavaScript the preview shows the icon that was saved.
(() => {
    const box = document.getElementById('icon');
    const preview = document.querySelector('[data-icon-preview]');

    if (!box || !preview) {
        return;
    }

    box.addEventListener('input', () => {
        preview.textContent = box.value.trim() || box.placeholder;
    });
})();
