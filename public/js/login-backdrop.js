// The login page's background video: starts it silently on a loop, unless the device asks for less motion or to
// save data, when the still frame stays. The button in the corner pauses and plays it, and the page remembers a pause
// for the next visit. Without JavaScript the still frame stays too.
(() => {
    const video = document.querySelector('.login-backdrop-video');
    const toggle = document.querySelector('[data-backdrop-toggle]');

    if (!video || !toggle) {
        return;
    }

    const lessMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const saveData = navigator.connection?.saveData === true;

    if (lessMotion || saveData) {
        return;
    }

    const storageKey = 'myapp.login-video.paused';
    const remember = (paused) => {
        try {
            paused ? localStorage.setItem(storageKey, '1') : localStorage.removeItem(storageKey);
        } catch {
            // Private windows or blocked storage: it plays again next time.
        }
    };
    let pausedBefore = false;
    try {
        pausedBefore = localStorage.getItem(storageKey) === '1';
    } catch {
        // As above.
    }

    const icon = toggle.querySelector('.material-icon');
    const show = (playing) => {
        icon.textContent = playing ? 'pause' : 'play_arrow';
        toggle.setAttribute('aria-label', playing ? 'Pause the background video' : 'Play the background video');
    };

    video.addEventListener('play', () => show(true));
    video.addEventListener('pause', () => show(false));
    toggle.addEventListener('click', () => {
        remember(!video.paused);
        video.paused ? video.play().catch(() => {}) : video.pause();
    });

    video.src = video.dataset.src;
    toggle.hidden = false;

    if (pausedBefore) {
        show(false);
        return;
    }

    // Browsers let a silent video start by itself; if one doesn't, the still frame stays and the button can start it.
    video.play().catch(() => show(false));
})();
