// The Games page (LibraryController): its row of tiles works as a games console's. Clicking a tile, or ← and → (Home
// and End too), chooses its game, which shows its title, buttons and cards over its own backdrop; clicking the chosen
// tile again, or Enter, opens the game (its first button). The tiles are tabs to screen readers. The chosen game is
// kept in the address (#higher-or-lower), so Back and a shared link come back to it.
(() => {
    const library = document.querySelector('[data-library]');

    if (!library) {
        return;
    }

    const list = library.querySelector('[data-library-tiles]');
    const tiles = [...list.querySelectorAll('[data-library-tile]')];
    const games = new Map([...library.querySelectorAll('[data-library-game]')].map((game) => [game.dataset.libraryGame, game]));
    const arts = new Map([...library.querySelectorAll('[data-library-art]')].map((art) => [art.dataset.libraryArt, art]));

    list.setAttribute('role', 'tablist');
    list.querySelectorAll('li').forEach((item) => item.setAttribute('role', 'presentation'));
    tiles.forEach((tile) => {
        const game = games.get(tile.dataset.libraryTile);
        tile.setAttribute('role', 'tab');
        tile.setAttribute('aria-controls', game.id);
        game.setAttribute('role', 'tabpanel');
    });

    const choose = (tile, { focus = false, remember = true } = {}) => {
        tiles.forEach((each) => {
            const chosen = each === tile;
            each.classList.toggle('is-selected', chosen);
            each.setAttribute('aria-selected', String(chosen));
            each.tabIndex = chosen ? 0 : -1;
            games.get(each.dataset.libraryTile).classList.toggle('is-shown', chosen);
            arts.get(each.dataset.libraryTile).classList.toggle('is-shown', chosen);
        });

        if (focus) {
            tile.focus();
        }

        tile.scrollIntoView({ block: 'nearest', inline: 'nearest' });

        if (remember) {
            history.replaceState(null, '', `#${tile.dataset.libraryTile}`);
        }
    };

    // The game itself, from its first button. A tile without one, like "More games coming soon", opens nothing.
    const open = (tile) => {
        const play = games.get(tile.dataset.libraryTile).querySelector('.library-play');

        if (play) {
            window.location.href = play.href;
        }
    };

    const chosen = () => tiles.find((tile) => tile.classList.contains('is-selected'));

    tiles.forEach((tile) => {
        tile.addEventListener('click', (event) => {
            event.preventDefault();

            if (tile === chosen()) {
                open(tile);
            } else {
                choose(tile, { focus: true });
            }
        });
    });

    // The keys, from a tile, or from nowhere in particular, as a console's controller would.
    document.addEventListener('keydown', (event) => {
        const from = tiles.includes(document.activeElement) ? document.activeElement : (document.activeElement === document.body ? chosen() : null);

        if (!from || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const index = tiles.indexOf(from);
        const to = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tiles.length - 1 }[event.key];

        if (to !== undefined) {
            event.preventDefault();
            choose(tiles[Math.max(0, Math.min(tiles.length - 1, to))], { focus: true });
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open(from);
        }
    });

    choose(tiles.find((tile) => `#${tile.dataset.libraryTile}` === window.location.hash) ?? tiles[0], { remember: false });
})();
