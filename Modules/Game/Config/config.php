<?php

return [
    'name' => 'Game',

    // Higher or Lower: a card with a police district's count of one crime type in one year, then another with its
    // count hidden, to guess whether it's higher or lower. Each card is a row of the Map module's dbo.CrimeStats.

    // Seconds to answer each card. The server allows a few more, for the answer to arrive over a slow connection,
    // then counts a late one as wrong. Short enough that looking the figure up on the map hardly pays.
    'seconds' => 20,
    'grace' => 4,

    // Seconds counting down before a solo game's first cards. Their time to answer starts after it.
    'ready' => 3,

    // Counts under this are left out, so a card with 1 crime doesn't give away that the next one is higher.
    'least' => 5,

    // Crime types the game leaves out, as no fun to guess at in a game among colleagues. Add others, like 'murder', to
    // leave them out too; the map and the dashboard still have them all.
    'left_out' => ['rape'],

    // How close the next card's count is to the one shown, by the score so far: at most this many times more or
    // fewer. The closer the counts, the harder the guess, so it gets harder as the score goes up.
    'closeness' => [
        0 => 5,
        5 => 3,
        10 => 2,
        20 => 1.5,
    ],

    // Each round's two cards are of one crime type, chosen at random each round. A type needs this many different
    // counts to be chosen, so the second card can be close to the first: armed gang robbery, with only a few counts
    // of 5 or more, isn't.
    'fewest_counts' => 10,

    // The cards are dealt from a deck read from dbo.CrimeStats once and kept in `store` for `keep` seconds, so dealing
    // doesn't read the table each time; new figures reach the game within that time. The file store, as the site runs as
    // one instance (DEPLOY.md); tests use the array store (phpunit.xml).
    'deck' => [
        'store' => env('GAME_DECK_STORE', 'file'),
        'keep' => 600,
    ],

    // Each card's Material icon, by its crime type, or failing that its category, as the dashboard's cards have them.
    'icons' => [
        'assault' => 'personal_injury',
        'murder' => 'dangerous',
        'property' => 'house',
        'break_in' => 'door_front',
        'theft_vehicle_motorcycle' => 'two_wheeler',
        'theft_vehicle_motorcar' => 'directions_car',
        'theft_vehicle_lorry' => 'local_shipping',
    ],

    // Live games, hosted from the Game scores page like a quiz night, of Higher or Lower or DistrictGuessr: everyone
    // joins with the game's code on their phone, and each round shows everyone the same two cards, or the same district,
    // at once, for the game's seconds to answer (config('game.seconds') or config('game.districtguessr.seconds')). An
    // answer scores more the sooner it comes.
    'live' => [
        // The rounds a host can choose from, and the choice to start with.
        'rounds' => ['least' => 3, 'most' => 30, 'default' => 10],
        // Seconds to get ready before each round can be answered, so every phone shows it at the same moment.
        'ready' => 3,
        // A right Higher or Lower answer's points. A DistrictGuessr pin's are its distance's (DistrictGuessr::points()).
        'points' => 1000,
        // The share of an answer's points lost by answering at the last moment rather than straight away, by game: half
        // in Higher or Lower; a quarter in DistrictGuessr, where how close the pin is counts most.
        'speed' => ['higher-or-lower' => 0.5, 'districtguessr' => 0.25],
        // Seconds an answer may take to arrive after the round closes.
        'grace' => 1,
        // Hours a game can be joined for after it's made.
        'hours' => 12,
        // Where each game's state is kept between the phones' checks, about every second, and for how many seconds, so
        // they don't each read the database. The file store, as the site runs as one instance (DEPLOY.md).
        'store' => 'file',
        'keep' => 2,
    ],

    // DistrictGuessr: a police district's name and its state, to find on a map of Malaysia with only its states'
    // outlines, so no town's name gives it away. The closer the pin to the district's (dbo.PoliceDistricts), the more
    // points: all of them within `perfect` km, falling away by about a third every `scale` km after.
    'districtguessr' => [
        'rounds' => 10,
        'seconds' => 40,
        // Seconds more for a guess to arrive, then it counts as none.
        'grace' => 4,
        // Seconds counting down before the first district, as in Higher or Lower.
        'ready' => 3,
        'points' => 1000,
        'perfect' => 2,
        'scale' => 100,
    ],

    // The longest name a player can give.
    'name_length' => 40,

    // How many times a minute the games can be used, counted apart from the rest of the public pages. Colleagues on one
    // Wi-Fi share one IP address, so what's counted by address is set for a room of them: page loads (`pages`), games
    // started (`starts`), live games joined (`joins`), and every guess and answer from the address together (`room`).
    // Each player is also counted on their own: a solo game's guesses by game (`guesses`), a live game's answers by
    // player (`answers`), plenty for one person, so no one's tries use up anyone else's.
    'limits' => [
        'pages' => 600,
        'starts' => 300,
        'joins' => 120,
        'room' => 3000,
        'guesses' => 90,
        'answers' => 30,
    ],
];
