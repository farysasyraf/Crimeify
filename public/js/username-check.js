// The Username field on Add user, Edit user and My profile (_info-fields): a moment after typing stops, asks the app
// (UserController@checkUsername) whether the username is one a username can be and no one has yet, and says so under
// the field. The username the field started with is the user's own, so it isn't asked about. Saving checks again,
// so this only tells sooner; without JavaScript, saving still says if it's taken.
(() => {
    const input = document.querySelector('[data-username-check]');
    const status = document.querySelector('[data-username-status]');
    const token = input?.form?.querySelector('input[name="_token"]')?.value;

    if (!input || !status || !token) {
        return;
    }

    const current = input.dataset.current.toLowerCase();
    // What saving said was wrong with the username, until the username is changed.
    let saved = input.closest('.field')?.querySelector('.field-error');
    let timer;
    let request;

    const show = (text, kind) => {
        status.textContent = text;
        status.className = `username-status${kind ? ` is-${kind}` : ''}`;
        status.hidden = text === '';

        if (kind === 'taken') {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
    };

    const check = async () => {
        const username = input.value.trim().toLowerCase();
        request?.abort();

        if (username === '' || username === current) {
            show('', '');
            return;
        }

        request = new AbortController();
        show('Checking…', 'checking');

        try {
            const response = await fetch(input.dataset.usernameCheck, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ username }),
                signal: request.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();
            show(result.message, result.available ? 'available' : 'taken');
        } catch (error) {
            if (error.name !== 'AbortError') {
                show("Couldn't check the username just now. Saving will check it.", 'checking');
            }
        }
    };

    input.addEventListener('input', () => {
        // The check says what's wrong with the new username instead.
        if (saved) {
            saved.remove();
            saved = null;
            input.classList.remove('is-invalid');
        }

        clearTimeout(timer);
        timer = setTimeout(check, 400);
    });

    // Back from a save that failed for another field, the username typed is still there: say whether it's free.
    if (!saved) {
        check();
    }
})();
