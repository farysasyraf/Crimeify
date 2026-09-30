// Left menu behaviour, as in AMV's sidebar: groups that open and close, a narrow icons-only menu on wide screens,
// and a slide-out drawer on smaller ones. Without JavaScript every group stays open and the menu sits above
// the page on smaller screens, so no link is ever out of reach.
(() => {
    const root = document.documentElement;
    const narrowKey = 'myapp.sidebar.narrow';

    const setGroupOpen = (button, open) => {
        button.setAttribute('aria-expanded', String(open));
        const list = document.getElementById(button.getAttribute('aria-controls'));
        if (list) {
            list.hidden = !open;
        }
    };

    // As in AMV, each page opens only the groups holding it.
    for (const button of document.querySelectorAll('.sidebar [data-submenu-toggle]')) {
        setGroupOpen(button, button.hasAttribute('data-active-branch'));
    }

    // The narrow menu is remembered by this browser; the page's head script applies it before drawing.
    const showNarrow = (narrow) => {
        for (const toggle of document.querySelectorAll('.menu-narrow-toggle')) {
            toggle.setAttribute('aria-pressed', String(narrow));
        }
    };

    const setNarrow = (narrow) => {
        root.classList.toggle('menu-narrow', narrow);
        showNarrow(narrow);
        try {
            localStorage.setItem(narrowKey, narrow ? '1' : '0');
        } catch {
            // Not remembered, but the menu still narrows.
        }
    };

    // The toggle sits in the top bar, which is drawn after this script runs.
    document.addEventListener('DOMContentLoaded', () => showNarrow(root.classList.contains('menu-narrow')));

    const setDrawerOpen = (open) => {
        root.classList.toggle('menu-open', open);
        for (const toggle of document.querySelectorAll('.menu-toggle')) {
            toggle.setAttribute('aria-expanded', String(open));
        }
        if (open) {
            document.querySelector('.sidebar a, .sidebar button:not(.side-close)')?.focus();
        }
    };

    document.addEventListener('click', (event) => {
        const groupButton = event.target.closest('.sidebar [data-submenu-toggle]');

        if (groupButton) {
            setGroupOpen(groupButton, groupButton.getAttribute('aria-expanded') !== 'true');
        } else if (event.target.closest('.menu-narrow-toggle')) {
            setNarrow(!root.classList.contains('menu-narrow'));
        } else if (event.target.closest('.menu-toggle')) {
            setDrawerOpen(!root.classList.contains('menu-open'));
        } else if (event.target.closest('.menu-backdrop, .side-close')) {
            setDrawerOpen(false);
            if (event.target.closest('.side-close')) {
                document.querySelector('.menu-toggle')?.focus();
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.classList.contains('menu-open')) {
            setDrawerOpen(false);
            document.querySelector('.menu-toggle')?.focus();
        }
    });
})();
