// A game's music (_music.blade.php): its track, looped and quiet, fading in. It starts as the page opens where the
// browser allows sound then: Chrome and Edge do after a click on the site that led here, such as a game's tile or Play
// on the Games page. Where it doesn't (Safari and Firefox ask for a tap on the page itself, and so does any browser
// for a page opened straight from an address), it starts with the first tap or key. The switch beside All games fades
// it out and back, and the browser remembers which for every game. Paused while the page is out of view. Its loudness
// goes through the Web Audio API where there is one, as iPhones ignore an audio element's own volume; otherwise
// through that.
(() => {
    const button = document.querySelector('[data-game-music]');

    if (!button) {
        return;
    }

    const key = 'crimeify.game.music';
    const loudness = Number(button.dataset.volume) || 0.35;
    const icon = button.querySelector('[data-game-music-icon]');
    const audio = new Audio();
    audio.src = button.dataset.gameMusic;
    audio.loop = true;
    // Fetched only once it's to play, so none is downloaded where the browser waits for a tap, or the music is off.
    audio.preload = 'none';

    // On unless turned off before.
    let wanted = true;
    try {
        wanted = localStorage.getItem(key) !== 'off';
    } catch {
        // Without the browser's storage, on each time.
    }

    let started = false;
    let gain = null;
    let context = null;
    let fading = null;

    // The loudness to the level given over a second or so, then what's next.
    const fade = (to, then = null) => {
        clearInterval(fading);
        clearTimeout(fading);
        if (gain) {
            const now = context.currentTime;
            gain.gain.cancelScheduledValues(now);
            gain.gain.setValueAtTime(gain.gain.value, now);
            gain.gain.linearRampToValueAtTime(to, now + (to > 0 ? 1.5 : 0.6));
            if (then) {
                fading = setTimeout(then, 650);
            }
            return;
        }
        fading = setInterval(() => {
            const step = to > audio.volume ? 0.02 : -0.05;
            audio.volume = Math.min(1, Math.max(0, Math.abs(to - audio.volume) <= Math.abs(step) ? to : audio.volume + step));
            if (audio.volume === to) {
                clearInterval(fading);
                then?.();
            }
        }, 40);
    };

    // Its sound through the Web Audio API, once it's allowed to play, its loudness at none to fade from.
    const route = () => {
        if (context || !window.AudioContext) {
            return;
        }
        try {
            context = new AudioContext();
            gain = context.createGain();
            gain.gain.value = 0;
            context.createMediaElementSource(audio).connect(gain).connect(context.destination);
            audio.volume = 1;
        } catch {
            context = null;
            gain = null;
        }
    };

    // The music, silent until the browser lets it play, then fading in. Whether it's playing.
    const play = async () => {
        if (!gain) {
            audio.volume = 0;
        }
        try {
            await audio.play();
        } catch {
            // Not allowed yet, or the file couldn't load: quiet, as it was.
            return false;
        }
        route();
        await context?.resume().catch(() => {});
        fade(loudness);
        return true;
    };

    const stop = () => fade(0, () => audio.pause());

    const label = () => {
        button.setAttribute('aria-pressed', String(wanted));
        icon.textContent = wanted ? 'music_note' : 'music_off';
        button.title = wanted ? t('Turn the music off') : t('Turn the music on');
    };

    // From here on, the music is the switch's to start and stop.
    const settle = () => {
        document.removeEventListener('pointerdown', begin, true);
        document.removeEventListener('keydown', begin, true);
        started = true;
    };

    // The first tap or key, but on the switch.
    const begin = (event) => {
        if (event.target.closest?.('[data-game-music]')) {
            return;
        }
        settle();
        if (wanted) {
            play();
        }
    };

    document.addEventListener('pointerdown', begin, true);
    document.addEventListener('keydown', begin, true);

    button.addEventListener('click', () => {
        wanted = !wanted;
        try {
            localStorage.setItem(key, wanted ? 'on' : 'off');
        } catch {
            // Not remembered, then.
        }
        label();
        settle();
        if (wanted) {
            play();
        } else {
            stop();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!started || !wanted) {
            return;
        }
        if (document.hidden) {
            audio.pause();
        } else {
            play();
        }
    });

    label();
    button.hidden = false;

    // Straight away, where the browser allows it; otherwise the first tap or key does.
    if (wanted) {
        play().then((playing) => {
            if (playing && !started) {
                settle();
            }
        });
    }
})();
