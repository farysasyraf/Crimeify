<?php

return [
    'name' => 'Map',

    // The boundaries of Malaysia's states and federal territories, in public/ (see malaysia-states-LICENSE.txt).
    'boundaries' => 'modules/map/malaysia-states.geojson',

    // Each region by its ISO 3166-2 code, which the boundaries file names it by (shapeISO), with its official name.
    'states' => [
        'MY-01' => ['name' => 'Johor', 'territory' => false],
        'MY-02' => ['name' => 'Kedah', 'territory' => false],
        'MY-03' => ['name' => 'Kelantan', 'territory' => false],
        'MY-04' => ['name' => 'Melaka', 'territory' => false],
        'MY-05' => ['name' => 'Negeri Sembilan', 'territory' => false],
        'MY-06' => ['name' => 'Pahang', 'territory' => false],
        'MY-07' => ['name' => 'Pulau Pinang', 'territory' => false],
        'MY-08' => ['name' => 'Perak', 'territory' => false],
        'MY-09' => ['name' => 'Perlis', 'territory' => false],
        'MY-10' => ['name' => 'Selangor', 'territory' => false],
        'MY-11' => ['name' => 'Terengganu', 'territory' => false],
        'MY-12' => ['name' => 'Sabah', 'territory' => false],
        'MY-13' => ['name' => 'Sarawak', 'territory' => false],
        'MY-14' => ['name' => 'Kuala Lumpur', 'territory' => true],
        'MY-15' => ['name' => 'Labuan', 'territory' => true],
        'MY-16' => ['name' => 'Putrajaya', 'territory' => true],
    ],

    // Crime by police district, from data.gov.my (Royal Malaysia Police and the Department of Statistics Malaysia,
    // CC BY 4.0): yearly counts, not single crimes, so the map shows one pin per police district.
    'crime' => [
        // Where "php artisan map:import-crime" downloads the latest figures from when no file is given.
        'source' => 'https://storage.data.gov.my/publicsafety/crime_district.csv',
        // The map's credit for the figures, linked to the dataset's page.
        'credit' => 'Crime data: PDRM & DOSM (CC BY 4.0)',
        'about' => 'https://data.gov.my/data-catalogue/crime_district',

        // Where each police district's pin goes, in this module (see police-districts-LICENSE.txt).
        'districts' => 'Database/data/police-districts.csv',

        // Police districts renamed over the years, merged under today's name so each has one pin and whole trends.
        'renamed' => [
            'Johor|Nusajaya' => 'Iskandar Puteri',
            'Kedah|Bandar Bharu' => 'Bandar Baharu',
            'Pahang|Cameron Highland' => 'Cameron Highlands',
            'Selangor|Sg. Buloh' => 'Sungai Buloh',
        ],

        // The data's two categories. "assault" is the police's violent crime: murder, rape, robbery and injury.
        'categories' => [
            'assault' => 'Violent crime',
            'property' => 'Property crime',
        ],

        // Each crime type in a category, in the order a popup lists them. "all" is the category's total.
        'types' => [
            'assault' => [
                'murder' => 'Murder',
                'rape' => 'Rape',
                'causing_injury' => 'Causing injury',
                'robbery_gang_armed' => 'Gang robbery, armed',
                'robbery_gang_unarmed' => 'Gang robbery, unarmed',
                'robbery_solo_armed' => 'Robbery, armed',
                'robbery_solo_unarmed' => 'Robbery, unarmed',
            ],
            'property' => [
                'break_in' => 'Break-in',
                'theft_vehicle_motorcycle' => 'Motorcycle theft',
                'theft_vehicle_motorcar' => 'Car theft',
                'theft_vehicle_lorry' => 'Lorry and van theft',
                'theft_other' => 'Other theft',
            ],
        ],
    ],

    // Crime by state and type from the Royal Malaysia Police's crime index tables, in this module (see
    // crime-by-state-LICENSE.txt), for the years data.gov.my has no police district figures for yet: 2024 so far.
    // "php artisan map:import-state-crime" loads them, and "map:import-crime" again after its own figures.
    'by_state' => [
        'file' => 'Database/data/crime-by-state.csv',
        // Where they're from, credited on the map and dashboard for those years.
        'source' => 'Royal Malaysia Police (PDRM), crime index',
        // Types these tables give as one figure where data.gov.my splits them: robbery, not its four kinds.
        'types' => [
            'assault' => ['robbery' => 'Robbery'],
        ],
    ],

    // The police stations "php artisan map:import-stations" starts dbo.PoliceStations with, in this module (see
    // police-stations-LICENSE.txt). The Police stations page keeps them up to date after that.
    'stations' => 'Database/data/police-stations.csv',
];
