// A live game's screen kept on, on the big screen and the players' phones (live-host.js, live.js), so none dims or
// locks in the middle of a round: the Screen Wake Lock API, taken again when the page comes back into view, as the
// browser lets go of it whenever it's hidden. And on phones that can (Android, not iPhones), a short buzz: the
// Vibration API. Browsers without either carry on as before.
window.CrimeifyAwake = (() => {
    let lock = null;
    let taking = null;
    let wanted = false;

    const take = () => {
        if (!wanted || lock || taking || document.visibilityState !== 'visible' || !('wakeLock' in navigator)) {
            return;
        }
        taking = navigator.wakeLock.request('screen')
            .then((taken) => {
                lock = taken;
                lock.addEventListener('release', () => { lock = null; });
                // Given up meanwhile, as the game finished.
                if (!wanted) {
                    lock.release();
                }
            })
            // Refused, as on a low battery: the screen dims as it usually would.
            .catch(() => {})
            .finally(() => { taking = null; });
    };

    document.addEventListener('visibilitychange', take);

    return {
        // On while the game is, off once it's over.
        keep(on) {
            wanted = on;
            if (on) {
                take();
            } else {
                lock?.release();
            }
        },

        // A buzz of so many milliseconds, or a pattern of them.
        buzz(pattern) {
            navigator.vibrate?.(pattern);
        },
    };
})();
