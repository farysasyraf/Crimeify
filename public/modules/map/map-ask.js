// The map's AI, in its card over the map (index.blade.php): "Ask the map" sends what the visitor would like to see,
// with the view shown, to MapAskController@ask, and the map goes to the view that comes back (crimeMap.go in map.js),
// with its caption; "What am I looking at?" sends the view shown to MapAskController@explain, and says what comes
// back. Each answer says which view it's for. Shown once the map can be read and moved; nothing changes on the map
// until an answer comes, and a problem is said in the card.
// map.js sets crimeMap up once MapLibre has loaded, and says so ("crime-map").
const mapAsk = () => {
    const card = document.querySelector('[data-map-ai]');

    if (!card || !window.crimeMap) {
        return;
    }

    const form = card.querySelector('[data-map-ai-form]');
    const input = form?.querySelector('input');
    const explain = card.querySelector('[data-map-ai-explain]');
    const answer = card.querySelector('[data-map-ai-answer]');
    const forWhat = card.querySelector('[data-map-ai-for]');
    const text = card.querySelector('[data-map-ai-text]');
    const regions = new Map([...document.querySelectorAll('[data-state]')].map((choice) => [choice.dataset.state, choice.dataset.name]));
    let asking = null;

    // "2023 · Selangor", or "2023 · Malaysia", for the view an answer is about.
    const label = (view) => [view.year, view.region === 'Malaysia' ? 'Malaysia' : regions.get(view.region) ?? view.region].filter(Boolean).join(' · ');

    // Busy while one is asked, so a second click doesn't ask again; a slow answer can be left.
    const busy = (on, what) => {
        card.classList.toggle('is-busy', on);
        card.setAttribute('aria-busy', String(on));
        for (const control of card.querySelectorAll('button, input')) {
            if (!control.matches('[data-map-ai-close]')) {
                control.disabled = on;
            }
        }
        if (on) {
            forWhat.textContent = what;
            text.textContent = t('Thinking…');
            answer.hidden = false;
            answer.classList.remove('is-problem');
        }
    };

    const say = (heading, words, problem = false) => {
        forWhat.textContent = heading;
        text.textContent = words;
        answer.classList.toggle('is-problem', problem);
        answer.hidden = false;
    };

    const send = async (url, options) => {
        asking?.abort();
        asking = new AbortController();
        const response = await fetch(url, { ...options, signal: asking.signal, headers: { Accept: 'application/json', ...(options?.headers ?? {}) } });
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.problem) {
            // Too many: for a minute, or with a day's limit reached, for longer.
            const wait = Number(response.headers.get('Retry-After')) || 60;
            const tooMany = wait > 120 ? t("That's all the questions for today: try again tomorrow.") : t("That's a lot of questions: try again in a minute.");
            throw new Error(body.problem ?? (response.status === 429 ? tooMany : t("The map's AI couldn't answer just now. Try again in a moment.")));
        }
        return body;
    };

    // "Ask the map": the view to go to, from the one shown now.
    const ask = async (question) => {
        busy(true, question);
        try {
            const body = await send(card.dataset.ask, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': card.dataset.token },
                body: JSON.stringify({ question, now: window.crimeMap.now() }),
            });
            await window.crimeMap.go(body);
            say(label(window.crimeMap.now()), body.caption ?? t('The map shows it now.'));
        } catch (problem) {
            if (problem.name !== 'AbortError') {
                say(question, problem.message, true);
            }
        } finally {
            busy(false);
        }
    };

    // "What am I looking at?": the view shown, described.
    const describe = async () => {
        const view = window.crimeMap.now();
        busy(true, label(view));
        try {
            const query = new URLSearchParams({ year: view.year ?? '', region: view.region, shade: view.shade, pins: view.pins, raised: view.raised ? '1' : '0' });
            const url = new URL(card.dataset.explain, location.href);
            query.forEach((value, key) => url.searchParams.set(key, value));
            const body = await send(url);
            say(label(body.view), body.text);
        } catch (problem) {
            if (problem.name !== 'AbortError') {
                say(label(view), problem.message, true);
            }
        } finally {
            busy(false);
        }
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        const question = input.value.trim();
        if (question.length >= 3) {
            ask(question);
        }
    });
    for (const idea of card.querySelectorAll('[data-map-ai-idea]')) {
        idea.addEventListener('click', () => {
            input.value = idea.textContent.trim();
            ask(input.value);
        });
    }
    explain.addEventListener('click', describe);
    card.querySelector('[data-map-ai-close]').addEventListener('click', () => {
        asking?.abort();
        answer.hidden = true;
        window.crimeMap.clearPicks();
    });

    window.crimeMap.ready.then(() => {
        card.hidden = false;
    });
};

if (window.crimeMap) {
    mapAsk();
} else {
    document.addEventListener('crime-map', mapAsk, { once: true });
}
