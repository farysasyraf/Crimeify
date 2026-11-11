{{-- A police district's profile (/public/areas/johor/batu-pahat), for someone deciding where to live or stay: what the
     figures say in sentences, the latest year's, its crime each year, how that changed beside its state's and
     Malaysia's, which crimes are most common, and its police stations (AreaProfile). The chart is drawn by areas.js
     with Chart.js, and its figures are in a table for screen readers. A shared link shows its chart, as the map's
     link to it does (DistrictPreview). --}}
@extends('layouts.public')

@php
    $district = $profile->district;
    $year = $profile->latestYear();
    $total = $year ? $profile->total($year) : 0;
    $number = fn (int $value) => number_format($value);
    // A change as a tag: ↓ in green for less crime, ↑ in red for more.
    $change = fn (?float $change) => $change === null ? '' : ($change === 0.0
        ? '<span class="area-change">'.e(__('No change')).'</span>'
        : '<span class="area-change '.($change < 0 ? 'is-down' : 'is-up').'">'.($change < 0 ? '↓ ' : '↑ ').e(\Modules\Map\Support\AreaProfile::size($change)).'</span>');
    // The chart's figures, for areas.js: property crime with violent crime on it, in --area-property's and --area-assault's colours.
    $chart = [
        'kind' => 'profile',
        // Every year's figures and crimes by type, for the year slider and each type's trend, with the names they're for.
        'eachYear' => $profile->eachYear(),
        'district' => $district->Name,
        'area' => $profile->area,
        'years' => array_column($profile->yearly(), 'year'),
        'series' => [
            ['label' => __('Property crime'), 'colour' => '#3987e5', 'counts' => array_column($profile->yearly(), 'property')],
            ['label' => __('Violent crime'), 'colour' => '#e66767', 'counts' => array_column($profile->yearly(), 'assault')],
        ],
    ];
@endphp

@section('title', $district->Name.' · '.__('Check an area'))
@section('description', $share['description'])

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/areas.css') }}" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ config('app.name') }}" />
    <meta property="og:title" content="{{ $share['title'] }}" />
    <meta property="og:description" content="{{ $share['description'] }}" />
    <meta property="og:url" content="{{ $share['url'] }}" />
    <meta property="og:image" content="{{ $share['image'] }}" />
    <meta property="og:image:type" content="image/png" />
    <meta property="og:image:width" content="{{ \Modules\Map\Support\DistrictPreview::Width }}" />
    <meta property="og:image:height" content="{{ \Modules\Map\Support\DistrictPreview::Height }}" />
    <meta property="og:image:alt" content="{{ $share['alt'] }}" />
    <meta name="twitter:card" content="summary_large_image" />
    <link rel="canonical" href="{{ $share['url'] }}" />
@endpush

@section('content')
<div class="area-page">
    {{-- The headline, as one row on a wide screen: where it is, its latest year's crime and how that changed, and where
         to go next. Its name glides here from the link chosen, where the browser can (areas.css, areas-motion.js). --}}
    <header class="area-card area-wide area-hero" data-area-hero>
        <span class="area-hero-icon" aria-hidden="true"><span class="material-icon">local_police</span></span>
        <div class="area-hero-place">
            <p class="area-kicker"><a href="{{ route('public.areas', $language) }}">{{ __('Check an area') }}</a></p>
            <h1>{{ $district->Name }}</h1>
            <p class="area-hero-where">
                <span class="material-icon" aria-hidden="true">location_on</span>
                <span>{{ __('Police district in :region', ['region' => $district->regionName()]) }}@if ($year) · {{ __('Crime :from–:to', ['from' => $profile->firstYear(), 'to' => $year]) }}@endif</span>
            </p>
        </div>
        @if ($year)
            <div class="area-hero-figure">
                <span class="area-hero-label">{{ __('Crimes reported in :year', ['year' => $year]) }}</span>
                <span class="area-hero-number"><strong>{{ $number($total) }}</strong> {!! $change($profile->latestChange()) !!}</span>
                @if ($profile->latestChange() !== null)
                    <span class="area-sub">{{ __('from :year', ['year' => $year - 1]) }}</span>
                @endif
            </div>
        @endif
        <div class="area-actions">
            <a class="btn btn-sm btn-primary" href="{{ $compareUrl }}"><span class="material-icon" aria-hidden="true">compare_arrows</span>{{ __('Compare with other areas') }}</a>
            <a class="btn btn-sm" href="{{ $mapUrl }}"><span class="material-icon" aria-hidden="true">map</span>{{ __('See it on the map') }}</a>
        </div>
    </header>

    {{-- The headline again, small, under the page's bar once it has scrolled away, so Compare is always at hand
         (areas-motion.js). It's inert, so out of reach, until then. --}}
    <div class="area-dock" data-area-dock inert>
        <div class="area-dock-bar">
            <span class="area-dock-icon" aria-hidden="true"><span class="material-icon">local_police</span></span>
            <span class="area-dock-name"><strong>{{ $district->Name }}</strong> <span class="area-sub">{{ $district->regionName() }}</span></span>
            @if ($year)
                <span class="area-dock-figure"><strong>{{ $number($total) }}</strong> <span class="area-sub">{{ __('crimes in :year', ['year' => $year]) }}</span> {!! $change($profile->latestChange()) !!}</span>
            @endif
            <a class="btn btn-sm btn-primary" href="{{ $compareUrl }}"><span class="material-icon" aria-hidden="true">compare_arrows</span>{{ __('Compare with other areas') }}</a>
        </div>
    </div>

    <section class="area-card area-wide" aria-labelledby="area-summary-heading">
        <h2 id="area-summary-heading">{{ __('What the figures say') }}</h2>
        <ul class="area-summary">
            @foreach ($profile->summary() as $sentence)
                <li>{{ $sentence }}</li>
            @endforeach
        </ul>
    </section>

    @if ($year)
        {{-- The year's crimes are in the headline; here, how they divide, and the change since the first year. --}}
        <section class="area-card area-wide" aria-labelledby="area-latest-heading">
            <header class="area-card-head">
                <h2 id="area-latest-heading" data-year-heading>{{ __('In :year', ['year' => $year]) }}</h2>
                {{-- Any year: these figures, the chart's year and the crimes by type follow it (areas.js). --}}
                @if (count($profile->years()) > 1)
                    <div class="area-year-pick" data-year-pick hidden>
                        <label for="area-year">{{ __('Year') }}</label>
                        <input type="range" id="area-year" min="0" max="{{ count($profile->years()) - 1 }}" value="{{ count($profile->years()) - 1 }}" step="1" aria-valuetext="{{ $year }}" />
                        <output for="area-year" data-year-shown>{{ $year }}</output>
                    </div>
                @endif
            </header>
            <dl class="area-stats area-stats-three">
                @foreach (config('map.crime.categories') as $category => $label)
                    <div class="area-stat">
                        <dt>{{ __($label) }}</dt>
                        <dd><strong data-figure="{{ $category }}">{{ $number($profile->total($year, $category)) }}</strong> <span class="area-sub" data-share="{{ $category }}">{{ __(':share of all', ['share' => \Modules\Map\Support\AreaProfile::percent($total > 0 ? $profile->total($year, $category) / $total * 100 : 0)]) }}</span></dd>
                    </div>
                @endforeach
                <div class="area-stat">
                    <dt>{{ __('Since :year', ['year' => $profile->firstYear()]) }}</dt>
                    <dd data-since>
                        @if ($profile->longChange()['here'] !== null)
                            <strong>{{ $profile->longChange()['here'] < 0 ? '−' : '+' }}{{ \Modules\Map\Support\AreaProfile::size($profile->longChange()['here']) }}</strong>
                            <span class="area-sub">{{ __('from :count in :year', ['count' => $number($profile->total($profile->firstYear())), 'year' => $profile->firstYear()]) }}</span>
                        @else
                            <strong>–</strong>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section class="area-card area-wide" aria-labelledby="area-years-heading">
            <header class="area-card-head">
                <h2 id="area-years-heading">{{ __('Crimes reported each year') }}</h2>
                <ul class="area-legend">
                    <li><span class="area-swatch" style="--swatch: var(--area-property)"></span>{{ __('Property crime') }}</li>
                    <li><span class="area-swatch" style="--swatch: var(--area-assault)"></span>{{ __('Violent crime') }}</li>
                </ul>
            </header>
            <div class="area-chart"><canvas id="area-years-chart" aria-hidden="true"></canvas></div>
            <div class="visually-hidden">
                <table>
                    <caption>{{ __('Crimes reported each year') }}</caption>
                    <thead><tr><th scope="col">{{ __('Year') }}</th><th scope="col">{{ __('Violent crime') }}</th><th scope="col">{{ __('Property crime') }}</th><th scope="col">{{ __('All') }}</th></tr></thead>
                    <tbody>
                        @foreach ($profile->yearly() as $each)
                            <tr><th scope="row">{{ $each['year'] }}</th><td>{{ $number($each['assault']) }}</td><td>{{ $number($each['property']) }}</td><td>{{ $number($each['total']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @php($long = $profile->longChange())
        @if ($long['here'] !== null)
            <section class="area-card" aria-labelledby="area-trend-heading">
                <h2 id="area-trend-heading">{{ __('Change from :from to :to', ['from' => $profile->firstYear(), 'to' => $year]) }}</h2>
                <dl class="area-trend">
                    @foreach ([$district->Name => $long['here'], $profile->area => $long['area'], 'Malaysia' => $long['national']] as $place => $moved)
                        @continue($moved === null)
                        <div class="area-trend-row">
                            <dt>{{ $place }}</dt>
                            <dd>
                                <span class="area-bar" aria-hidden="true"><span @class(['area-bar-fill', 'is-down' => $moved < 0, 'is-up' => $moved > 0]) style="--size: {{ min(100, abs(round($moved))) }}"></span></span>
                                {!! $change($moved) !!}
                            </dd>
                        </div>
                    @endforeach
                </dl>
                <p class="area-note">{{ __('Each bar runs from no change to a change of 100%.') }}</p>
            </section>
        @endif

        @php($mix = $profile->mix())
        @if ($mix !== [])
            <section @class(['area-card', 'area-wide' => $long['here'] === null]) aria-labelledby="area-mix-heading">
                <header class="area-card-head">
                    <h2 id="area-mix-heading" data-mix-heading>{{ __('Crimes by type in :year', ['year' => $year]) }}</h2>
                    <ul class="area-legend">
                        <li><span class="area-swatch" style="--swatch: var(--area-1)"></span>{{ $district->Name }}</li>
                        <li><span class="area-swatch area-swatch-mark"></span>{{ __('Across :area', ['area' => $profile->area]) }}</li>
                    </ul>
                </header>
                <ul class="area-mix">
                    @foreach ($mix as $type)
                        {{-- Each type opens its own trend (areas.js). --}}
                        <li class="area-mix-row" data-type="{{ $type['type'] }}" data-category="{{ $type['category'] }}">
                            <button type="button" class="area-mix-pick" aria-expanded="false" aria-controls="area-type-trend">{{ $type['label'] }}</button>
                            <span class="area-mix-track" aria-hidden="true">
                                <span class="area-mix-fill" style="--share: {{ $type['share'] }}"></span>
                                <span class="area-mix-mark" style="--share: {{ $type['areaShare'] }}"></span>
                            </span>
                            <span class="area-mix-value">
                                <strong data-here>{{ \Modules\Map\Support\AreaProfile::percent($type['share']) }}</strong> · {{ $profile->area }} <span data-there>{{ \Modules\Map\Support\AreaProfile::percent($type['areaShare']) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="area-note">{{ __("Each type's share of the area's violent and property crime. The line is its share across :area.", ['area' => $profile->area]) }} <span data-type-hint hidden>{{ __('Choose a type for how it changed each year.') }}</span></p>

                {{-- A type's crimes each year here, and how that compares with the area's change (areas.js). --}}
                <div class="area-type-trend" id="area-type-trend" data-type-trend hidden>
                    <header class="area-card-head">
                        <h3 data-type-heading></h3>
                        <button type="button" class="area-type-close" data-type-close aria-label="{{ __('Close') }}"><span class="material-icon" aria-hidden="true">close</span></button>
                    </header>
                    <div class="area-chart area-chart-small"><canvas id="area-type-chart" aria-hidden="true"></canvas></div>
                    <p class="area-note" data-type-read aria-live="polite"></p>
                </div>
            </section>
        @endif

        <script type="application/json" id="area-data">@json($chart)</script>
    @endif

    @if ($living)
        @include('map::areas._living')
    @endif

    @php($stations = $profile->stations())
    @if ($stations->isNotEmpty())
        <section class="area-card area-wide" aria-labelledby="area-stations-heading">
            <h2 id="area-stations-heading">{{ __('Police stations') }}</h2>
            <ul class="area-stations">
                @foreach ($stations as $station)
                    <li class="area-station">
                        <h3>{{ $station->Name }}</h3>
                        @if ($station->Address)
                            <p>{{ $station->Address }}</p>
                        @endif
                        <p>
                            @if ($station->phoneLink())
                                <a href="{{ $station->phoneLink() }}">{{ $station->Phone }}</a> ·
                            @endif
                            <a href="{{ $station->googleMapsUrl() }}" target="_blank" rel="noopener">Google Maps</a> ·
                            <a href="{{ $station->wazeUrl() }}" target="_blank" rel="noopener">Waze</a>
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('map::areas._about')
</div>

<script src="{{ versioned_asset('modules/map/areas-motion.js') }}" defer></script>
<script src="{{ versioned_asset('vendor/chartjs/chart.umd.min.js') }}" defer></script>
<script src="{{ versioned_asset('modules/map/areas.js') }}" defer></script>
@endsection
