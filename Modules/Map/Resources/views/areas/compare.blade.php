{{-- Two or three police districts side by side (/public/areas/compare?areas[]=…): the latest year's figures in a table,
     each one's crime over the years from the same start, so places of any size compare, and which crimes are most
     common in each (AreaController@compare, AreaProfile). Each area keeps its colour throughout: blue, orange, teal.
     The chart is drawn by areas.js with Chart.js, and its figures are in a table for screen readers. --}}
@extends('layouts.public')

@php
    $colours = ['var(--area-1)', 'var(--area-2)', 'var(--area-3)'];
    $hex = ['#3987e5', '#d95926', '#199e70'];
    $from = $years[0] ?? null;
    $year = end($years) ?: null;
    $previous = $year !== null && in_array($year - 1, $years, true) ? $year - 1 : null;
    $number = fn (int $value) => number_format($value);
    $percent = fn (float $share) => \Modules\Map\Support\AreaProfile::percent($share);
    $change = fn (?float $change) => $change === null ? '–' : ($change === 0.0
        ? '<span class="area-change">'.e(__('No change')).'</span>'
        : '<span class="area-change '.($change < 0 ? 'is-down' : 'is-up').'">'.($change < 0 ? '↓ ' : '↑ ').e(\Modules\Map\Support\AreaProfile::size($change)).'</span>');
    $names = $profiles->map(fn ($profile) => $profile->district->Name)->all();
    // The chart's figures, for areas.js: each area's against its first year, in its colour, and Malaysia's.
    $chart = count($years) < 2 ? null : [
        'kind' => 'compare',
        'years' => $years,
        'series' => $profiles->values()->map(fn ($profile, $at) => ['label' => $profile->district->Name, 'colour' => $hex[$at], 'values' => $profile->indexed($years), 'counts' => array_map(fn ($each) => $profile->total($each), $years)])->all(),
        'national' => ['label' => 'Malaysia', 'colour' => '#8a939d', 'values' => $profiles->first()->nationalIndexed($years)],
    ];
@endphp

@section('title', __('Compare areas'))
@section('description', __('Crime in :areas side by side.', ['areas' => implode(', ', $names)]))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/areas.css') }}" />
@endpush

@section('content')
<div class="page-head area-head">
    <div>
        <p class="area-kicker"><a href="{{ route('public.areas', $language) }}">{{ __('Check an area') }}</a></p>
        <h1>{{ __('Compare areas') }}</h1>
        <p class="muted">{{ implode(' · ', $names) }}</p>
    </div>
</div>

<div class="area-page">
    <section class="area-card area-wide" aria-labelledby="area-choose-heading">
        <h2 id="area-choose-heading">{{ __('Change the areas') }}</h2>
        @include('map::areas._picker')
    </section>

    @if ($year)
        <section class="area-card area-wide" aria-labelledby="area-table-heading">
            <h2 id="area-table-heading">{{ __('In :year', ['year' => $year]) }}</h2>
            <div class="area-table-wrap">
                <table class="area-table">
                    <thead>
                        <tr>
                            <td></td>
                            @foreach ($profiles as $at => $profile)
                                <th scope="col"><a href="{{ $profileUrls[$at] }}"><span class="area-swatch" style="--swatch: {{ $colours[$at] }}"></span>{{ $profile->district->Name }}</a></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">{{ __('State') }}</th>
                            @foreach ($profiles as $profile)<td>{{ $profile->district->regionName() }}</td>@endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Crimes reported') }}</th>
                            @foreach ($profiles as $profile)<td><strong>{{ $number($profile->total($year)) }}</strong></td>@endforeach
                        </tr>
                        @if ($previous)
                            <tr>
                                <th scope="row">{{ __('Change from :year', ['year' => $previous]) }}</th>
                                @foreach ($profiles as $profile)<td>{!! $change(\Modules\Map\Support\AreaProfile::change($profile->total($previous), $profile->total($year))) !!}</td>@endforeach
                            </tr>
                        @endif
                        @if ($from !== $year)
                            <tr>
                                <th scope="row">{{ __('Change from :year', ['year' => $from]) }}</th>
                                @foreach ($profiles as $profile)<td>{!! $change(\Modules\Map\Support\AreaProfile::change($profile->total($from), $profile->total($year))) !!}</td>@endforeach
                            </tr>
                        @endif
                        @foreach (config('map.crime.categories') as $category => $label)
                            <tr>
                                <th scope="row">{{ __($label) }}</th>
                                @foreach ($profiles as $profile)
                                    @php($total = $profile->total($year))
                                    <td>{{ $number($profile->total($year, $category)) }} <span class="area-sub">({{ $percent($total > 0 ? $profile->total($year, $category) / $total * 100 : 0) }})</span></td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr>
                            <th scope="row">{{ __('Most common crime') }}</th>
                            @foreach ($profiles as $profile)
                                @php($top = collect($profile->mix())->first(fn ($type) => $type['count'] > 0))
                                <td>{{ $top ? $top['label'].' ('.$percent($top['share']).')' : '–' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Police stations') }}</th>
                            @foreach ($profiles as $profile)<td>{{ $profile->stations()->count() }}</td>@endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="area-note">{{ __("The counts aren't per person: a district with more people has more crime. Compare the changes and the mix of crimes.") }}</p>
        </section>

        @if (count($years) > 1)
            <section class="area-card area-wide" aria-labelledby="area-index-heading">
                <header class="area-card-head">
                    <h2 id="area-index-heading">{{ __('Crime each year, against :year', ['year' => $from]) }}</h2>
                    <ul class="area-legend">
                        @foreach ($profiles as $at => $profile)
                            <li><span class="area-swatch" style="--swatch: {{ $colours[$at] }}"></span>{{ $profile->district->Name }}</li>
                        @endforeach
                        <li><span class="area-swatch area-swatch-line" style="--swatch: #8a939d"></span>Malaysia</li>
                    </ul>
                </header>
                <div class="area-chart"><canvas id="area-index-chart" aria-hidden="true"></canvas></div>
                <p class="area-note">{{ __('Each line starts at 100 in :year, so places of any size can be compared: 50 is half as much crime as then.', ['year' => $from]) }}</p>
                <div class="visually-hidden">
                    <table>
                        <caption>{{ __('Crimes reported each year') }}</caption>
                        <thead><tr><th scope="col">{{ __('Year') }}</th>@foreach ($profiles as $profile)<th scope="col">{{ $profile->district->Name }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($years as $each)
                                <tr><th scope="row">{{ $each }}</th>@foreach ($profiles as $profile)<td>{{ $number($profile->total($each)) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <script type="application/json" id="area-data">@json($chart)</script>
        @endif

        @php($types = collect($profiles->map(fn ($profile) => $profile->mix())->all())->flatten(1)->groupBy('type')->sortByDesc(fn ($rows) => $rows->sum('share')))
        <section class="area-card area-wide" aria-labelledby="area-mix-heading">
            <header class="area-card-head">
                <h2 id="area-mix-heading">{{ __('Crimes by type in :year', ['year' => $year]) }}</h2>
                <ul class="area-legend">
                    @foreach ($profiles as $at => $profile)
                        <li><span class="area-swatch" style="--swatch: {{ $colours[$at] }}"></span>{{ $profile->district->Name }}</li>
                    @endforeach
                </ul>
            </header>
            <ul class="area-mix">
                @foreach ($types as $type => $rows)
                    <li class="area-mix-group" style="--bars: {{ $profiles->count() }}">
                        <span class="area-mix-label">{{ $rows->first()['label'] }}</span>
                        @foreach ($profiles as $at => $profile)
                            @php($share = collect($profile->mix())->firstWhere('type', $type)['share'] ?? 0)
                            <span class="area-mix-line">
                                <span class="area-mix-track" aria-hidden="true"><span class="area-mix-fill" style="--share: {{ $share }}; --swatch: {{ $colours[$at] }}"></span></span>
                                <span class="area-mix-value"><span class="visually-hidden">{{ $profile->district->Name }}:</span> {{ $percent($share) }}</span>
                            </span>
                        @endforeach
                    </li>
                @endforeach
            </ul>
            <p class="area-note">{{ __("Each type's share of the area's violent and property crime.") }}</p>
        </section>
    @endif

    @include('map::areas._about')
</div>

<script src="{{ versioned_asset('vendor/chartjs/chart.umd.min.js') }}" defer></script>
<script src="{{ versioned_asset('modules/map/areas.js') }}" defer></script>
@endsection
