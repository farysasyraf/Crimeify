// Checking an uploaded Excel file, shown as it goes, with AMV's process bars: on the Crime data and Police stations
// pages, the upload form (data-upload-progress) opens a dialog with a bar for each step. Unlike AMV's, which fill by
// the clock, each bar shows what has really happened: the upload's fills as the file is sent, and the server's two
// steps, reading the file and comparing it with the saved data, are requests of their own, each ticked once it's
// done, with what it found. Then the review page opens, or, with nothing to change, the list says so. A file with
// problems turns its step coral and lists them, with Close. When the only problems are names that are new here, which
// may be meant, Proceed with Review beside it checks the file again taking them as they are (new_names). Without
// JavaScript, the form is sent as it is, and the server does every step at once.
// The form names its rows: data-row="figure", data-rows="figures", data-saved="the saved figures".
(() => {
    const forms = document.querySelectorAll('form[data-upload-progress]');
    if (forms.length === 0 || typeof HTMLDialogElement !== 'function' || !window.FormData || !window.fetch) {
        return;
    }

    // A moment after each tick, so every step is seen done before the next one starts, as AMV's half a second.
    const settle = 300;
    const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });
    const count = (n, one, many) => `${n.toLocaleString('en-MY')} ${n === 1 ? one : many}`;
    const size = (bytes) => (bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`);

    let dialog = null;
    let running = false;
    // The form whose file is being checked, for Proceed with Review to check again.
    let checking = null;

    // The dialog, made the first time it's needed: a title, a line saying what's happening, the steps, the problems
    // if there are any, and Close, with Proceed with Review when it can, shown once the check has stopped. Escape
    // doesn't close it while it runs. Checked again, it starts over where it is.
    const open = (title, status) => {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'progress-dialog';
            dialog.setAttribute('aria-labelledby', 'progress-title');
            dialog.innerHTML = `
                <h2 id="progress-title" tabindex="-1"></h2>
                <p class="progress-status" aria-live="polite"></p>
                <ol class="progress-steps"></ol>
                <div class="alert alert-error progress-problems" role="alert" hidden>
                    <strong>The file wasn't used, and nothing changed.</strong>
                    <ul></ul>
                </div>
                <div class="progress-actions" hidden>
                    <button type="button" class="btn" data-close>Close</button>
                    <button type="button" class="btn btn-primary" data-proceed hidden>Proceed with Review</button>
                </div>`;
            document.body.append(dialog);
            dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
            dialog.querySelector('[data-proceed]').addEventListener('click', () => check(checking, true));
            dialog.addEventListener('cancel', (event) => {
                if (running) {
                    event.preventDefault();
                }
            });
        }

        dialog.querySelector('h2').textContent = title;
        dialog.querySelector('.progress-status').textContent = status;
        dialog.querySelector('.progress-steps').replaceChildren();
        dialog.querySelector('.progress-problems').hidden = true;
        dialog.querySelector('.progress-problems ul').replaceChildren();
        dialog.querySelector('.progress-actions').hidden = true;
        dialog.querySelector('[data-proceed]').hidden = true;
        if (dialog.open) {
            // Its buttons have gone, so the title takes the focus.
            dialog.querySelector('h2').focus();
        } else {
            dialog.showModal();
        }
    };

    // A step's row: its bar, with its label inside, drawn twice so it reads on the fill as on the empty track, and
    // its mark beside it: a tick when it's done, a cross where it stopped. A running step's bar is striped and moving;
    // it fills to its percentage if it has one, like the upload's, and right across if not.
    const step = (label) => {
        const row = document.createElement('li');
        row.innerHTML = `
            <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <span class="progress-fill"></span>
                <span class="progress-label"></span>
                <span class="progress-label progress-label-on-fill" aria-hidden="true"></span>
            </div>
            <span class="material-icon progress-mark" aria-hidden="true"></span>`;
        dialog.querySelector('.progress-steps').append(row);
        const track = row.querySelector('.progress-track');
        let filled = 0;

        const show = (state, text, percent, known) => {
            filled = percent;
            row.className = `progress-step is-${state}`;
            row.style.setProperty('--progress', `${percent}%`);
            row.querySelectorAll('.progress-label').forEach((span) => { span.textContent = text; });
            row.querySelector('.progress-mark').textContent = { done: 'check_circle', failed: 'cancel' }[state] ?? '';
            track.setAttribute('aria-label', text);
            if (known) {
                track.setAttribute('aria-valuenow', String(Math.floor(percent)));
            } else {
                track.removeAttribute('aria-valuenow');
            }
        };
        show('waiting', label, 0, true);

        return {
            run: (percent = null) => (percent === null
                ? show('running', `${label}…`, 100, false)
                : show('running', `${label}: ${Math.floor(percent)}%`, percent, true)),
            done: (text) => show('done', text, 100, true),
            fail: () => show('failed', `${label}: stopped`, Math.max(filled, 8), false),
        };
    };

    // Why a step stopped: the file's problems, to fix in it, or what the server's answer means. Names that are new
    // here (newNames) may be meant, so the check can go on with them.
    const failure = (status, body = null) => {
        if (status === 422 && Array.isArray(body?.errors?.file)) {
            return body.newNames === true
                ? {
                    problems: body.errors.file,
                    advice: 'These names aren\'t here yet. Fix them in the file and upload it again, or, if they\'re meant to be new, proceed to review what it would change.',
                    proceed: true,
                }
                : { problems: body.errors.file, advice: 'Fix these in the file, then upload it again.' };
        }
        if (status === 404 && body?.message) {
            return { problems: [body.message] };
        }

        return { problems: [{
            0: 'The server couldn\'t be reached. Check the connection and try again.',
            401: 'You\'ve been logged out. Log in again, then upload the file again.',
            403: 'You\'re not allowed to upload files here.',
            413: 'The file is too large to upload.',
            419: 'This page has expired. Reload it, then upload the file again.',
        }[status] ?? `The server stopped with error ${status}. Try again, or reload the page.`] };
    };

    // Send the form, and its file with it: how much has gone as it goes, then, once it's all gone, the server reads
    // it, and answers with how many rows it read and the address of the next step.
    const upload = (form, newNames, progress, sent) => new Promise((resolve, reject) => {
        const data = new FormData(form);
        if (newNames) {
            data.set('new_names', '1');
        }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                progress((event.loaded / event.total) * 100);
            }
        });
        xhr.upload.addEventListener('load', sent);
        xhr.addEventListener('load', () => (xhr.status === 200 && xhr.response ? resolve(xhr.response) : reject(failure(xhr.status, xhr.response))));
        xhr.addEventListener('error', () => reject(failure(0)));
        xhr.send(data);
    });

    // The next step, at the address the last one gave.
    const post = async (url, token) => {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
            });
        } catch {
            throw failure(0);
        }
        const body = await response.json().catch(() => null);
        if (!response.ok || !body) {
            throw failure(response.status, body);
        }

        return body;
    };

    // Check the form's file, or, with newNames, check it again taking names that are new here as they are.
    const check = async (form, newNames = false) => {
        checking = form;
        const file = form.elements.file.files[0];
        const { row, rows, saved } = form.dataset;
        open(`Checking ${file.name}`, newNames
            ? 'With its new names as they are. Nothing changes until you apply it on the next page.'
            : 'Nothing changes until you apply it on the next page.');
        const sending = step(`Uploading ${file.name}`);
        const reading = step(`Reading the ${rows}`);
        const comparing = step(`Comparing with ${saved}`);
        let current = sending;
        running = true;

        try {
            sending.run(0);
            const read = await upload(form, newNames, (percent) => sending.run(percent), () => {
                sending.done(`Uploaded ${file.name}, ${size(file.size)}`);
                current = reading;
                reading.run();
            });
            // A file too small to report its upload is sent all the same.
            if (current === sending) {
                sending.done(`Uploaded ${file.name}, ${size(file.size)}`);
                current = reading;
            }
            reading.done(`Read ${count(read.rows, row, rows)}`);
            await wait(settle);

            current = comparing;
            comparing.run();
            const compared = await post(read.next, form.elements._token.value);
            comparing.done(compared.changes > 0 ? `Found ${count(compared.changes, 'change', 'changes')}` : 'No changes found');
            dialog.querySelector('h2').textContent = `Checked ${file.name}`;
            dialog.querySelector('.progress-status').textContent = compared.changes > 0
                ? 'Opening the page that shows what it would change…'
                : 'It has the same data as the page already has, so there\'s nothing to change.';
            await wait(settle);
            // The dialog stays until the next page has come.
            window.location.assign(compared.next);
        } catch (stopped) {
            if (!Array.isArray(stopped?.problems)) {
                console.error(stopped);
            }
            const { problems = ['Something went wrong while checking the file. Try again, or reload the page.'], advice = '', proceed = false } = stopped ?? {};
            running = false;
            current.fail();
            dialog.querySelector('h2').textContent = `Checking ${file.name} stopped`;
            dialog.querySelector('.progress-status').textContent = advice;
            const list = dialog.querySelector('.progress-problems ul');
            list.replaceChildren(...problems.map((problem) => {
                const item = document.createElement('li');
                item.textContent = problem;
                return item;
            }));
            dialog.querySelector('.progress-problems').hidden = false;
            dialog.querySelector('[data-proceed]').hidden = !proceed;
            dialog.querySelector('.progress-actions').hidden = false;
            // Close first: fixing the file is the likelier way on.
            dialog.querySelector('[data-close]').focus();
        }
    };

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            // Without a file, the browser says one is needed.
            if (!form.elements.file?.files?.length) {
                return;
            }
            event.preventDefault();
            if (!running) {
                check(form);
            }
        });
    });

    // Back from the review page, the list may come back as it was left, with the dialog still open.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && dialog?.open) {
            running = false;
            dialog.close();
        }
    });
})();
