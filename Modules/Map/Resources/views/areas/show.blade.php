{{-- A police district's profile (/public/areas/johor/batu-pahat), for someone deciding where to live or stay: what the
     figures say in sentences, the latest year's, its crime each year, how that changed beside its state's and
     Malaysia's, which crimes are most common, and its police stations (AreaProfile). The chart is drawn by areas.js
     with Chart.js, and its figures are in a table for screen readers. A shared link shows its chart, as the map's
     link to it does (DistrictPreview). --}}
@extends('layouts.public')

@php
    $district = $profile->district;
    $year = $profile->latestYear();
    $number = fn (int $value) => number_format($value);
    // A change as a tag: ↓ in green for less crime, ↑ in red for more.
    $change = fn (?float $change) => $change === null ? '' : ($change === 0.0
        ? '<span class="area-change">'.e(__('No change')).'</span>'
        : '<span class="area-change '.($change < 0 ? 'is-down' : 'is-up').'">'.($change < 0 ? '↓ ' : '↑ ').e(\Modules\Map\Support\AreaProfile::size($change)).'</span>');
    // The chart's figures, for areas.js: property crime with violent crime on it, in --area-property's and --area-assault's colours.
    $chart = [
        'kind' => 'profile',
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
<div class="page-head area-head">
    <div>
        <p class="area-kicker"><a href="{{ route('public.areas', $language) }}">{{ __('Check an area') }}</a></p>
        <h1>{{ $district->Name }}</h1>
        <p class="muted">
            {{ __('Police district in :region', ['region' => $district->regionName()]) }}@if ($year) · {{ __('Crime :from–:to', ['from' => $profile->firstYear(), 'to' => $year]) }}@endif
        </p>
    </div>
    <div class="area-actions">
        <a class="btn btn-sm" href="{{ $compareUrl }}"><span class="material-icon" aria-hidden="true">compare_arrows</span>{{ __('Compare with other areas') }}</a>
        <a class="btn btn-sm" href="{{ $mapUrl }}"><span class="material-icon" aria-hidden="true">map</span>{{ __('See it on the map') }}</a>
    </div>
</div>

<div class="area-page">
    <section class="area-card area-wide" aria-labelledby="area-summary-heading">
        <h2 id="area-summary-heading">{{ __('What the figures say') }}</h2>
        <ul class="area-summary">
            @foreach ($profile->summary() as $sentence)
                <li>{{ $sentence }}</li>
            @endforeach
        </ul>
    </section>

    @if ($year)
        @php($total = $profile->total($year))
        <section class="area-card area-wide" aria-labelledby="area-latest-heading">
            <h2 id="area-latest-heading">{{ __('In :year', ['year' => $year]) }}</h2>
            <dl class="area-stats">
                <div class="area-stat">
                    <dt>{{ __('Crimes reported') }}</dt>
                    <dd><strong>{{ $number($total) }}</strong> {!! $change($profile->latestChange()) !!}@if ($profile->latestChange() !== null) <span class="area-sub">{{ __('from :year', ['year' => $year - 1]) }}</span>@endif</dd>
                </div>
                @foreach (config('map.crime.categories') as $category => $label)
                    <div class="area-stat">
                        <dt>{{ __($label) }}</dt>
                        <dd><strong>{{ $number($profile->total($year, $category)) }}</strong> <span class="area-sub">{{ __(':share of all', ['share' => \Modules\Map\Support\AreaProfile::percent($total > 0 ? $profile->total($year, $category) / $total * 100 : 0)]) }}</span></dd>
                    </div>
                @endforeach
                <div class="area-stat">
                    <dt>{{ __('Since :year', ['year' => $profile->firstYear()]) }}</dt>
                    <dd>
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
                    <h2 id="area-mix-heading">{{ __('Crimes by type in :year', ['year' => $year]) }}</h2>
                    <ul class="area-legend">
                        <li><span class="area-swatch" style="--swatch: var(--area-1)"></span>{{ $district->Name }}</li>
                        <li><span class="area-swatch area-swatch-mark"></span>{{ __('Across :area', ['area' => $profile->area]) }}</li>
                    </ul>
                </header>
                <ul class="area-mix">
                    @foreach ($mix as $type)
                        <li class="area-mix-row">
                            <span>{{ $type['label'] }}</span>
                            <span class="area-mix-track" aria-hidden="true">
                                <span class="area-mix-fill" style="--share: {{ $type['share'] }}"></span>
                                <span class="area-mix-mark" style="--share: {{ $type['areaShare'] }}"></span>
                            </span>
                            <span class="area-mix-value">
                                <strong>{{ \Modules\Map\Support\AreaProfile::percent($type['share']) }}</strong> · {{ $profile->area }} {{ \Modules\Map\Support\AreaProfile::percent($type['areaShare']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="area-note">{{ __("Each type's share of the area's violent and property crime. The line is its share across :area.", ['area' => $profile->area]) }}</p>
            </section>
        @endif

        <script type="application/json" id="area-data">@json($chart)</script>
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

<script src="{{ versioned_asset('vendor/chartjs/chart.umd.min.js') }}" defer></script>
<script src="{{ versioned_asset('modules/map/areas.js') }}" defer></script>
@endsection
