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

    // The share of cards that keep the crime type of the one before, to compare one district with another; the rest
    // can be any type.
    'same_type' => 0.5,

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

    // The longest name a player can give.
    'name_length' => 40,
];
