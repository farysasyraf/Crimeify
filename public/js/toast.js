// Notifications as toasts at the top right, as AMV's toast(status, message): SweetAlert2's toast with the status's
// icon and a coloured edge, gone after a few seconds (a bar counts them down), and kept while the pointer is on it.
// Scripts call toast('success' | 'error' | 'warning' | 'info', 'message'). What the server says after a save, or
// what went wrong with a form, comes as the page's .flash boxes (layouts/_flash), shown here as toasts instead.
// Without JavaScript, or if SweetAlert2 didn't load, those boxes show at the top of the page.
(() => {
    const statuses = ['success', 'error', 'warning', 'info'];

    // The app's toned-down colours, as the badges and alerts use.
    const colours = { success: '#7fd8a4', error: '#ff8f8a', warning: '#f5b041', info: '#3aaaf2' };

    // SweetAlert2 shows one box at a time, so a toast waits for the one before it.
    let queue = Promise.resolve();

    window.toast = (status, message) => {
        if (typeof Swal === 'undefined') {
            return false;
        }

        status = statuses.includes(status) ? status : 'info';

        queue = queue.then(() => Swal.fire({
            toast: true,
            position: 'top-end',
            icon: status,
            iconColor: colours[status],
            title: message,
            theme: 'dark',
            showConfirmButton: false,
            showCloseButton: true,
            closeButtonAriaLabel: 'Close this message',
            // Long enough to read: 3 seconds, or more for a longer message, up to 10.
            timer: Math.min(10000, Math.max(3000, message.length * 60)),
            timerProgressBar: true,
            customClass: { popup: `swal-toast swal-toast-${status}` },
            didOpen: (popup) => {
                popup.addEventListener('mouseenter', Swal.stopTimer);
                popup.addEventListener('mouseleave', Swal.resumeTimer);
            },
        }));

        return true;
    };

    const flashes = document.querySelectorAll('.flash[data-toast]');

    if (typeof Swal === 'undefined') {
        flashes.forEach((flash) => flash.classList.add('flash-shown'));
        return;
    }

    flashes.forEach((flash) => toast(flash.dataset.toast, flash.textContent.trim()));
})();
