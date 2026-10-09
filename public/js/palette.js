// The command palette: Ctrl+K (⌘K on a Mac), or Search in the top bar, opens a box over the page to find a page, user,
// police district or station by name and go to it. The results come from the server (PaletteController) a moment
// after typing stops, only ever what the user can open; with nothing typed, the pages. ↑ and ↓ go through them, Enter
// opens the one marked, and Escape or a click outside closes the box. A result is a link, so Ctrl+click or the middle
// button opens it in a new tab.
(() => {
    const opener = document.querySelector('[data-palette]');

    if (!opener || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    const mac = /Mac|iPhone|iPad/.test(navigator.userAgentData?.platform ?? navigator.platform);
    opener.querySelector('kbd').textContent = mac ? '⌘K' : 'Ctrl K';
    opener.title = `Search (${mac ? '⌘K' : 'Ctrl+K'})`;
    opener.hidden = false;

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
    dialog.setAttribute('aria-label', 'Search pages, users, police districts and stations');

    const bar = element('div', 'palette-search');
    const glass = element('span', 'material-icon', 'search');
    glass.setAttribute('aria-hidden', 'true');
    const input = element('input');
    Object.assign(input, { type: 'text', autocomplete: 'off', spellcheck: false, placeholder: 'Search pages, users, police districts and stations' });
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

    // Each source's results under its heading, the first one marked.
    const draw = (groups, term) => {
        results = [];
        list.replaceChildren(...groups.map((group, number) => {
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
        }));

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
        if (event.target.closest('.palette-option') && !event.ctrlKey && !event.metaKey) {
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
