<?php

return [
    'name' => 'Dashboard',

    // The six cards of figures, each adding up crime types from the Map module's figures as category.type
    // (the types are in config('map.crime.types')); "all" is a category's total. Icons are Material Icons names.
    'cards' => [
        'all' => ['label' => 'All crime', 'icon' => 'local_police', 'tone' => 'blue', 'count' => ['assault.all', 'property.all']],
        'violent' => ['label' => 'Violent crime', 'icon' => 'personal_injury', 'tone' => 'coral', 'count' => ['assault.all']],
        'property' => ['label' => 'Property crime', 'icon' => 'house', 'tone' => 'green', 'count' => ['property.all']],
        'murder' => ['label' => 'Murder', 'icon' => 'dangerous', 'tone' => 'pink', 'count' => ['assault.murder']],
        'break_in' => ['label' => 'Break-ins', 'icon' => 'door_front', 'tone' => 'amber', 'count' => ['property.break_in']],
        'vehicles' => [
            'label' => 'Vehicle theft', 'icon' => 'two_wheeler', 'tone' => 'violet',
            'count' => ['property.theft_vehicle_motorcycle', 'property.theft_vehicle_motorcar', 'property.theft_vehicle_lorry'],
        ],
    ],

    // The charts' colours, toned down from full saturation so they don't glow on the dark cards: each category's
    // line on the crime over the years chart, then the slices of the crime by type chart, largest first.
    'colours' => [
        'categories' => ['assault' => '#ff8a80', 'property' => '#3aaaf2'],
        'slices' => ['#3aaaf2', '#ff8a80', '#7fd8a4', '#f5b041', '#b39ddb', '#6b7785'],
    ],

    // How many of the largest crime types the crime by type chart shows on their own; the rest are added up.
    'types_shown' => 5,

    // Short names for the regions on the crime by state chart, by ISO 3166-2 code, as Malaysia abbreviates them.
    'abbreviations' => [
        'MY-01' => 'JHR', 'MY-02' => 'KDH', 'MY-03' => 'KTN', 'MY-04' => 'MLK', 'MY-05' => 'NSN', 'MY-06' => 'PHG',
        'MY-07' => 'PNG', 'MY-08' => 'PRK', 'MY-09' => 'PLS', 'MY-10' => 'SGR', 'MY-11' => 'TRG', 'MY-12' => 'SBH',
        'MY-13' => 'SWK', 'MY-14' => 'KUL', 'MY-15' => 'LBN', 'MY-16' => 'PJY',
    ],
];
