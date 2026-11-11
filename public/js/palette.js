// The command palette: Ctrl+K (⌘K on a Mac), or Search in the top bar, opens a box over the page to find a page, user,
// police district or station by name and go to it. The results come from the server (PaletteController) a moment
// after typing stops, only ever what the user can open; with nothing typed, the pages. ↑ and ↓ go through them, Enter
// opens the one marked, and Escape or a click outside closes the box. A result is a link, so Ctrl+click or the middle
// button opens it in a new tab. With data-ask, a question about the crime figures can be asked too: Ask Crimeify
// (AskController) answers it in the box, from the app's own figures, with links to the districts it names.
(() => {
    const opener = document.querySelector('[data-palette]');

    if (!opener || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    const mac = /Mac|iPhone|iPad/.test(navigator.userAgentData?.platform ?? navigator.platform);
    opener.querySelector('kbd').textContent = mac ? '⌘K' : 'Ctrl K';
    opener.title = `Search (${mac ? '⌘K' : 'Ctrl+K'})`;
    opener.hidden = false;

    // Ask Crimeify's address and the form token to post to it, if it's set up.
    const asking = opener.dataset.ask ? { url: opener.dataset.ask, token: opener.dataset.token } : null;
    const prompt = asking ? 'Search, or ask about the crime figures' : 'Search pages, users, police districts and stations';

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    const dialog = element('dialog', 'palette');
    dialog.setAttribute('aria-label', prompt);

    const bar = element('div', 'palette-search');
    const glass = element('span', 'material-icon', 'search');
    glass.setAttribute('aria-hidden', 'true');
    const input = element('input');
    Object.assign(input, { type: 'text', autocomplete: 'off', spellcheck: false, placeholder: prompt });
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', 'palette-results');
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-label', 'Search');
    const escape = element('kbd', '', 'Esc');
    escape.setAttribute('aria-hidden', 'true');
    bar.append(glass, input, escape);

    const list = element('div', 'palette-results');
    list.id = 'palette-results';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Results');

    // How many results there are, or that there are none, read out as they change.
    const status = element('p', 'palette-status');
    status.setAttribute('aria-live', 'polite');

    const help = element('p', 'palette-help');
    help.setAttribute('aria-hidden', 'true');
    help.append(element('kbd', '', '↑'), element('kbd', '', '↓'), ' to move ', element('kbd', '', 'Enter'), ' to open ', element('kbd', '', 'Esc'), ' to close');

    dialog.append(bar, list, status, help);
    document.body.append(dialog);

    let results = [];
    let active = -1;
    let request = null;
    let typing = null;

    // The part of a label that matches what was typed, marked.
    const marked = (label, term) => {
        const at = term ? label.toLocaleLowerCase().indexOf(term.toLocaleLowerCase()) : -1;
        const text = element('span', 'palette-label');

        if (at < 0) {
            text.textContent = label;
        } else {
            text.append(label.slice(0, at), element('mark', '', label.slice(at, at + term.length)), label.slice(at + term.length));
        }
        return text;
    };

    const mark = (index) => {
        active = index;
        results.forEach((option, at) => option.setAttribute('aria-selected', String(at === index)));

        if (results[index]) {
            input.setAttribute('aria-activedescendant', results[index].id);
            results[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    // Whether what's typed reads as a question, and whether it could be one at all: a few words, or a question's
    // first word or mark, in English or Bahasa Melayu.
    const questionWords = /^(what|which|how|why|where|when|who|whose|is|are|was|were|did|does|do|can|has|have|compare|show|tell|list|apa|apakah|mana|manakah|berapa|bagaimana|kenapa|mengapa|adakah|bila|siapa|bandingkan|senaraikan)\b/i;
    const isQuestion = (term) => /\?\s*$/.test(term) || questionWords.test(term) || term.split(/\s+/).length >= 4;
    const mayBeQuestion = (term) => term.length >= 3 && (isQuestion(term) || term.split(/\s+/).length >= 2);

    // The option to ask Ask Crimeify what's typed, under its own heading.
    const askGroup = (term) => {
        const section = element('div', 'palette-group');
        const heading = element('p', 'palette-heading', 'Ask Crimeify');
        heading.id = 'palette-heading-ask';
        section.setAttribute('role', 'group');
        section.setAttribute('aria-labelledby', heading.id);

        const option = element('a', 'palette-option palette-ask');
        option.id = 'palette-option-ask';
        option.href = '#';
        option.tabIndex = -1;
        option.setAttribute('role', 'option');
        const icon = element('span', 'material-icon', 'auto_awesome');
        icon.setAttribute('aria-hidden', 'true');
        option.append(icon, element('span', 'palette-label', `Ask: “${term}”`), element('span', 'palette-about', 'Answered by Claude from the crime figures'));
        option.addEventListener('mousemove', () => active !== results.indexOf(option) && mark(results.indexOf(option)));
        option.addEventListener('click', (event) => {
            event.preventDefault();
            ask(term);
        });

        section.append(heading, option);
        return { section, option };
    };

    // Each source's results under its heading, the first one marked. A question goes first, as asking it is most
    // likely what's wanted; a couple of words go last.
    const draw = (groups, term) => {
        results = [];
        const sections = groups.map((group, number) => {
            const section = element('div', 'palette-group');
            const heading = element('p', 'palette-heading', group.heading);
            heading.id = `palette-heading-${number}`;
            section.setAttribute('role', 'group');
            section.setAttribute('aria-labelledby', heading.id);
            section.append(heading);

            for (const result of group.results) {
                const option = element('a', 'palette-option');
                option.id = `palette-option-${results.length}`;
                option.href = result.url;
                option.tabIndex = -1;
                option.setAttribute('role', 'option');
                const icon = element('span', 'material-icon', result.icon);
                icon.setAttribute('aria-hidden', 'true');
                option.append(icon, marked(result.label, term), element('span', 'palette-about', result.about));
                option.addEventListener('mousemove', () => active !== results.indexOf(option) && mark(results.indexOf(option)));
                results.push(option);
                section.append(option);
            }

            return section;
        });

        if (asking && mayBeQuestion(term)) {
            const { section, option } = askGroup(term);
            if (isQuestion(term)) {
                sections.unshift(section);
                results.unshift(option);
            } else {
                sections.push(section);
                results.push(option);
            }
        }
        list.replaceChildren(...sections);

        const count = results.length;
        status.textContent = count === 0 ? (term ? `No matches for “${term}”` : 'Nothing to show') : `${count} result${count === 1 ? '' : 's'}`;
        status.classList.toggle('palette-status-empty', count === 0);
        mark(count > 0 ? 0 : -1);
    };

    const search = () => {
        const term = input.value.trim();
        request?.abort();
        request = new AbortController();

        const url = new URL(opener.dataset.palette, window.location.href);
        url.searchParams.set('q', term);

        fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(response.statusText);
                }
                return response.json();
            })
            .then(({ groups }) => draw(groups, term))
            .catch((problem) => {
                if (problem.name !== 'AbortError') {
                    results = [];
                    list.replaceChildren();
                    status.textContent = "Search isn't working just now. Try again in a moment.";
                    status.classList.add('palette-status-empty');
                }
            });
    };

    // A link in an answer, only to a page of this app; anything else stays text.
    const ownPage = (href) => {
        try {
            const url = new URL(href, window.location.href);
            return url.origin === window.location.origin ? url.href : null;
        } catch {
            return null;
        }
    };

    // A line of an answer, with its **bold** and [links](…).
    const inline = (text) => {
        const nodes = [];
        let last = 0;
        for (const match of text.matchAll(/\*\*(.+?)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g)) {
            nodes.push(text.slice(last, match.index));
            const href = match[1] === undefined ? ownPage(match[3]) : null;
            if (match[1] !== undefined) {
                nodes.push(element('strong', '', match[1]));
            } else if (href) {
                const link = element('a', '', match[2]);
                link.href = href;
                nodes.push(link);
            } else {
                nodes.push(match[2]);
            }
            last = match.index + match[0].length;
        }
        nodes.push(text.slice(last));
        return nodes;
    };

    // An answer's Markdown, as far as Ask Crimeify writes it: paragraphs, and lists with - or numbers.
    const rendered = (answer) => {
        const blocks = [];
        let items = null;
        for (const line of answer.split('\n')) {
            const item = line.match(/^\s*(?:([-*•])|\d+[.)])\s+(.*)$/);
            if (item) {
                const kind = item[1] ? 'ul' : 'ol';
                if (items?.tagName.toLowerCase() !== kind) {
                    items = element(kind);
                    blocks.push(items);
                }
                const entry = element('li');
                entry.append(...inline(item[2]));
                items.append(entry);
                continue;
            }
            items = null;
            if (line.trim() !== '') {
                const paragraph = element('p');
                paragraph.append(...inline(line.trim().replace(/^#+\s*/, '')));
                blocks.push(paragraph);
            }
        }
        return blocks;
    };

    // Ask Crimeify a question: the box shows it, then the answer, or why there isn't one. Typing again searches again.
    const ask = (question) => {
        clearTimeout(typing);
        request?.abort();
        request = new AbortController();
        results = [];
        mark(-1);

        const answer = element('div', 'palette-answer-body');
        answer.append(element('p', 'palette-answer-wait', 'Looking at the figures…'));
        const panel = element('div', 'palette-answer');
        panel.append(element('p', 'palette-answer-question', question), answer);
        list.replaceChildren(panel);
        status.textContent = 'Asking Ask Crimeify…';
        status.classList.remove('palette-status-empty');

        fetch(asking.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': asking.token },
            body: JSON.stringify({ question }),
            signal: request.signal,
        })
            .then(async (response) => {
                const body = await response.json().catch(() => ({}));
                if (response.ok && body.answer) {
                    return body.answer;
                }
                throw new Error({
                    419: 'This page has been open a long time. Reload it to ask.',
                    429: "You've asked a lot just now. Try again in a little while.",
                }[response.status] ?? body.problem ?? body.message ?? "Ask Crimeify couldn't answer just now. Try again in a moment.");
            })
            .then((text) => {
                answer.replaceChildren(...rendered(text));
                panel.append(element('p', 'palette-answer-note', "Answered by Claude from Crimeify's figures. It can get things wrong, so check a figure on its page before you rely on it."));
                status.textContent = 'Answer ready';
            })
            .catch((problem) => {
                if (problem.name !== 'AbortError') {
                    answer.replaceChildren(element('p', 'palette-answer-problem', problem.message));
                    status.textContent = problem.message;
                }
            });
    };

    const open = () => {
        if (dialog.open) {
            return;
        }
        input.value = '';
        dialog.showModal();
        input.focus();
        search();
    };

    // The dialog puts the focus back where it was when it closes.
    const close = () => dialog.open && dialog.close();

    opener.addEventListener('click', open);

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            dialog.open ? close() : open();
        }
    });

    input.addEventListener('input', () => {
        clearTimeout(typing);
        typing = setTimeout(search, 120);
    });

    input.addEventListener('keydown', (event) => {
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && results.length > 0) {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            mark((active + step + results.length) % results.length);
        } else if (event.key === 'Enter' && results[active]) {
            event.preventDefault();
            // As a click on it would.
            results[active].click();
        }
    });

    // A result opened in this tab closes the box, so going back to the page doesn't find it still open.
    list.addEventListener('click', (event) => {
        if (event.target.closest('.palette-option:not(.palette-ask)') && !event.ctrlKey && !event.metaKey) {
            close();
        }
    });

    // Only a click that starts and ends outside the box closes it, not a selection dragged out of it.
    let pressedOutside = false;
    dialog.addEventListener('pointerdown', (event) => { pressedOutside = event.target === dialog; });
    dialog.addEventListener('click', (event) => {
        if (pressedOutside && event.target === dialog) {
            close();
        }
    });
})();
