<?php

return [
    'name' => 'Map',

    // The boundaries of Malaysia's states and federal territories, in public/ (see malaysia-states-LICENSE.txt).
    'boundaries' => 'modules/map/malaysia-states.geojson',

    // The street map under the regions, drawn by MapLibre GL JS (map.js) from a style: Crimeify's own, dark like the
    // app, in public/ (see style-LICENSE.txt), on OpenFreeMap's free vector tiles of OpenStreetMap, which need no key.
    // MAP_STYLE_URL gives another style's address instead, such as a tile provider's; its credits come with it.
    'style' => env('MAP_STYLE_URL', 'modules/map/style.json'),

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

    // Each region's population by year, from the Department of Statistics Malaysia, to shade the map by crime per
    // 100,000 people rather than by count. "php artisan map:import-population" loads it from this module's file (see
    // population-by-state-LICENSE.txt), or with --download, DOSM's latest from source.
    'population' => [
        'file' => 'Database/data/population-by-state.csv',
        'source' => 'https://storage.dosm.gov.my/population/population_state.csv',
        // The map's credit for the figures, linked to the dataset's page.
        'credit' => 'Population: DOSM (CC BY 4.0)',
        'about' => 'https://open.dosm.gov.my/data-catalogue/population_state',
    ],

    // Places and addresses by name, for the map's search and Check an area (PlaceSearch): Photon, komoot's search of
    // OpenStreetMap's places, whose public server may be asked as someone types, within fair use.
    'places' => [
        'source' => env('MAP_PLACES_URL', 'https://photon.komoot.io/api/'),
        // Credited on the map with a link here, once its places are shown.
        'about' => 'https://photon.komoot.io',
    ],

    // "Living here" on a police district's profile: income, spending, poverty, unemployment, schools and amenities, from
    // the Department of Statistics Malaysia and the Ministry of Education, by administrative district (AreaLiving). They
    // come with the code, as population-by-state.csv does, so a release needs no database work; "php artisan
    // map:update-living" makes file from these sources again when they're updated. areas gives the administrative
    // district each police district's pin is in (see district-living-LICENSE.txt).
    'living' => [
        'file' => 'Database/data/district-living.csv',
        'areas' => 'Database/data/police-district-areas.csv',
        'sources' => [
            'hies_district' => 'https://storage.dosm.gov.my/hies/hies_district.csv',
            'hies_state' => 'https://storage.dosm.gov.my/hies/hies_state.csv',
            'hh_income' => 'https://storage.dosm.gov.my/hies/hh_income.csv',
            'hh_poverty' => 'https://storage.dosm.gov.my/hies/hh_poverty.csv',
            'lfs_district' => 'https://storage.dosm.gov.my/labour/lfs_district.csv',
            'lfs_state_sex' => 'https://storage.dosm.gov.my/labour/lfs_state_sex.csv',
            'lfs_year' => 'https://storage.dosm.gov.my/labour/lfs_year.csv',
            'hh_access_amenities' => 'https://storage.dosm.gov.my/hies/hh_access_amenities.csv',
            'schools_district' => 'https://storage.data.gov.my/education/schools_district.csv',
        ],
        // Spellings the tidying in UpdateLivingFigures can't join to the others, as "State|Spelling" => the name to use.
        'aliases' => [
            'Pahang|Cameron Highland' => 'Cameron Highlands',
            'Pahang|Kuala Lipis' => 'Lipis',
            'Perak|Larut Matang & Selama' => 'Larut dan Matang',
            'Sarawak|Tanjong Manis' => 'Tanjung Manis',
            'Terengganu|Hulu' => 'Hulu Terengganu',
        ],
        'about' => 'https://open.dosm.gov.my/data-catalogue/hies_district',
    ],

    // Ask Crimeify, in the command palette for logged-in users: a question about the crime figures, answered by Claude
    // from this app's figures, which it looks up with AskCrimeifyTools (AskCrimeify). Off without ANTHROPIC_API_KEY.
    // Every answer costs money, so each is kept `cache_minutes`, and each user may ask `per_minute` and `per_day`
    // questions. `rounds` is how many times Claude may look something up before it must answer. Effort is how hard it
    // thinks: low is quick and cheap, and enough for looking figures up; medium or high for harder questions.
    'ask' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ASK_CRIMEIFY_MODEL', 'claude-opus-5-5'),
        'effort' => env('ASK_CRIMEIFY_EFFORT', 'low'),
        'rounds' => 6,
        'per_minute' => 6,
        'per_day' => 60,
        'cache_minutes' => 60,
        // The map's AI (AskTheMap, ExplainView) asks with the same key, model and effort, for everyone on the public
        // map. "Ask the map" counts towards a logged-in user's `per_minute` and `per_day`; a visitor who isn't logged
        // in may ask `guest_per_minute` and `guest_per_day`, by IP address, and all of them together `guests_per_day`,
        // so what the public can spend in a day has a ceiling. "What am I looking at?": each view's description is kept
        // `explain_hours`, or until the figures change, so a view is paid for once, and each visitor may ask
        // `explain_per_minute` and `explain_per_day`, by IP address.
        'guest_per_minute' => 3,
        'guest_per_day' => 20,
        'guests_per_day' => 300,
        'explain_hours' => 24,
        'explain_per_minute' => 10,
        'explain_per_day' => 200,
    ],

    // The police stations "php artisan map:import-stations" starts dbo.PoliceStations with, in this module (see
    // police-stations-LICENSE.txt). The Police stations page keeps them up to date after that.
    'stations' => 'Database/data/police-stations.csv',
];
