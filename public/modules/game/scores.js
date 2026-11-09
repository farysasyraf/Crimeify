// Game scores' Host a live game form: each game's own choice shown only while that game is chosen, Higher or Lower's
// Mode and DistrictGuessr's Level (data-live-for). The page draws the right one to start with (is-other on the other);
// this keeps it so as the Game changes. Select2 boxes announce their changes the usual way too (select2-init.js).
(() => {
    const game = document.querySelector('.scores-live-form #live-game');

    if (!game) {
        return;
    }

    const fields = game.form.querySelectorAll('[data-live-for]');
    const show = () => fields.forEach((field) => field.classList.toggle('is-other', field.dataset.liveFor !== game.value));

    game.addEventListener('change', show);
    show();
})();
