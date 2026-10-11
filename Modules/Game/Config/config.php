<?php

return [
    'name' => 'Game',

    // Higher or Lower: a card with a police district's count of one crime type in one year, then another with its
    // count hidden, to guess whether it's higher or lower. Each card is a row of the Map module's dbo.CrimeStats.

    // Seconds to answer each card. The server allows a few more, for the answer to arrive over a slow connection,
    // then counts a late one as wrong. Short enough that looking the figure up on the map hardly pays.
    'seconds' => 20,
    'grace' => 4,

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

    // Live games, hosted from the Game scores page like a quiz night: everyone joins with the game's code on their
    // phone, and each round shows everyone the same two cards at once, for config('game.seconds') to answer. A right
    // answer scores more the sooner it comes.
    'live' => [
        // The rounds a host can choose from, and the choice to start with.
        'rounds' => ['least' => 3, 'most' => 30, 'default' => 10],
        // Seconds to get ready before each round's cards can be answered, so every phone shows them at the same moment.
        'ready' => 3,
        // A right answer's points: all of them straight away, half at the last moment.
        'points' => 1000,
        // Seconds an answer may take to arrive after the round closes.
        'grace' => 1,
        // Hours a game can be joined for after it's made.
        'hours' => 12,
        // Where each game's state is kept between the phones' checks, about every second, and for how many seconds, so
        // they don't each read the database. The file store, as the site runs as one instance (DEPLOY.md).
        'store' => 'file',
        'keep' => 2,
    ],

    // The longest name a player can give.
    'name_length' => 40,
];
