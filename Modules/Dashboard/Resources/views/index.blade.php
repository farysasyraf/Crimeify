{{-- In the app for logged-in users, and at /public/dashboard for everyone, in the public pages' layout. --}}
@extends($public ? 'layouts.public' : 'layouts.app')

@section('title', __('Dashboard'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/dashboard/dashboard.css') }}" />
@endpush

{{-- The page to start from after logging in: a year's crime figures on dark cards, in the style of the sidebar and
     the Map page's popups. The charts are drawn by dashboard.js with Chart.js, from the figures in #dashboard-data;
     each has its figures in a table for screen readers, and the cards' figures are plain text. --}}
@section('content')
<div class="page-head">
    <div>
        <h1>{{ __('Dashboard') }}</h1>
        <p class="muted">
            @if ($years)
                {{ $byState
                    ? __("Crime in Malaysia in :year, from the Royal Malaysia Police's figures for each state.", ['year' => $year])
                    : __("Crime in Malaysia in :year, from the Royal Malaysia Police's figures for each police district.", ['year' => $year]) }}
            @else
                {{ __("Crime in Malaysia, from the Royal Malaysia Police's figures for each police district.") }}
            @endif
        </p>
    </div>
    @if ($years)
        <form method="get" action="{{ $pageUrl }}" class="dash-year" data-year-form>
            <label for="dash-year">{{ __('Year') }}</label>
            <select id="dash-year" name="year">
                @foreach (array_reverse($years) as $each)
                    <option value="{{ $each }}" @selected($each === $year)>{{ $each }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm" data-year-submit>{{ __('Show') }}</button>
        </form>
    @endif
</div>

@if (! $years)
    <div class="empty">
        <p>{{ __('There are no crime figures yet.') }}</p>
        {{-- How to load them is for the app's administrators, not the public. --}}
        @unless ($public)
            <p>Load them from data.gov.my with <code>php artisan map:import-crime</code>.</p>
        @endunless
    </div>
@else
    @php
        $number = fn (?int $value) => number_format($value ?? 0);
        // How a card's count compares with the year before, in words, e.g. "Down 7% from 1,324 in 2022".
        $sinceText = fn (array $card) => match (true) {
            $card['change']['direction'] === 'same' => __('Same as in :year', ['year' => $previousYear]),
            $card['change']['percent'] === null => __('Up from 0 in :year', ['year' => $previousYear]),
            default => __($card['change']['direction'] === 'up' ? 'Up :size from :count in :year' : 'Down :size from :count in :year', [
                'size' => $card['change']['percent'].'%', 'count' => $number($card['previous']), 'year' => $previousYear,
            ]),
        };
    @endphp

    <div class="dash">
        <section class="dash-card dash-trend" aria-labelledby="trend-heading">
            <header class="dash-card-head">
                <h2 id="trend-heading">{{ __('Crime over the years') }}</h2>
                <ul class="dash-legend">
                    @foreach ($charts['trend']['series'] as $series)
                        <li><span class="dash-swatch" style="--swatch: {{ $series['colour'] }}"></span>{{ $series['label'] }}</li>
                    @endforeach
                </ul>
            </header>
            <div class="dash-chart"><canvas id="trend-chart" aria-hidden="true"></canvas></div>
            <table class="visually-hidden">
                <caption>{{ __('Crime in Malaysia each year') }}</caption>
                <thead>
                    <tr><th scope="col">{{ __('Year') }}</th>@foreach ($charts['trend']['series'] as $series)<th scope="col">{{ $series['label'] }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($charts['trend']['years'] as $index => $each)
                        <tr><th scope="row">{{ $each }}</th>@foreach ($charts['trend']['series'] as $series)<td>{{ $number($series['counts'][$index]) }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="dash-card dash-regions" aria-labelledby="regions-heading">
            <header class="dash-card-head">
                <h2 id="regions-heading">{{ __('Crime by state, :year', ['year' => $year]) }}</h2>
                @if ($mapUrl)
                    <a class="dash-link" href="{{ $mapUrl }}">{{ __('Open the map') }}</a>
                @endif
            </header>
            <div class="dash-chart"><canvas id="regions-chart" aria-hidden="true"></canvas></div>
            @if ($byState && $countedIn)
                <p class="dash-note">{{ __(":year's figures are by state, so :list.", ['year' => $year, 'list' => collect($countedIn)->map(fn ($under, $region) => __(":region's are in :under's", ['region' => $region, 'under' => $under]))->join(', ', ' '.__('and').' ')]) }}</p>
            @endif
            <table class="visually-hidden">
                <caption>{{ __('Crime by state in :year, most first', ['year' => $year]) }}</caption>
                <thead>
                    <tr><th scope="col">{{ __('State') }}</th>@foreach (config('map.crime.categories') as $label)<th scope="col">{{ __($label) }}</th>@endforeach<th scope="col">{{ __('All crime') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($charts['regions'] as $region)
                        <tr><th scope="row">{{ $region['name'] }}</th>@foreach (array_keys(config('map.crime.categories')) as $category)<td>{{ $number($region['totals'][$category] ?? 0) }}</td>@endforeach<td>{{ $number($region['total']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="dash-card dash-types" aria-labelledby="types-heading">
            <header class="dash-card-head">
                <h2 id="types-heading">{{ __('Crime by type, :year', ['year' => $year]) }}</h2>
            </header>
            <div class="dash-types-body">
                <div class="dash-donut">
                    <canvas id="types-chart" aria-hidden="true"></canvas>
                    <p class="dash-donut-centre" aria-hidden="true">
                        <strong data-donut-count>{{ $number($charts['types']['total']) }}</strong>
                        <span data-donut-label>{{ __('crimes') }}</span>
                    </p>
                </div>
                <ul class="dash-slices">
                    @foreach ($charts['types']['slices'] as $slice)
                        <li style="--swatch: {{ $slice['colour'] }}">
                            <span class="dash-swatch"></span>
                            <span class="dash-slice-label">{{ $slice['label'] }}</span>
                            <span class="dash-slice-share">{{ number_format($slice['share'], 1) }}%</span>
                            <span class="dash-badge">{{ $number($slice['count']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @if ($byState)
                <p class="dash-note">{{ __(":year's figures by state give robbery as one type, where earlier years have its four kinds.", ['year' => $year]) }}</p>
            @endif
        </section>

        <section class="dash-cards" aria-label="{{ $previousYear ? __('Crime in :year, compared with :previous', ['year' => $year, 'previous' => $previousYear]) : __('Crime in :year', ['year' => $year]) }}">
            @foreach ($cards as $card)
                @php $highest = max(1, ...array_values($card['history'])); @endphp
                <article class="dash-card dash-figure tone-{{ $card['tone'] }}">
                    <header class="dash-figure-head">
                        <h3>{{ $card['label'] }}</h3>
                        <span class="material-icon dash-figure-icon" aria-hidden="true">{{ $card['icon'] }}</span>
                    </header>
                    <p class="dash-figure-value">{{ $number($card['value']) }}</p>
                    <div class="dash-figure-foot">
                        <span class="dash-bars" aria-hidden="true" title="{{ __(':from to :to', ['from' => array_key_first($card['history']), 'to' => array_key_last($card['history'])]) }}">
                            @foreach ($card['history'] as $each => $count)
                                <span @class(['chosen' => $each === $year]) style="--bar: {{ max(6, round($count / $highest * 100)) }}%"></span>
                            @endforeach
                        </span>
                        @if ($card['change'])
                            <span class="dash-change dash-{{ $card['change']['direction'] }}" title="{{ $sinceText($card) }}">
                                <span aria-hidden="true">{{ ['up' => '▲', 'down' => '▼', 'same' => '='][$card['change']['direction']] }} {{ $card['change']['percent'] ?? '' }}{{ $card['change']['percent'] === null ? __('new') : '%' }}</span>
                                <span class="visually-hidden">{{ $sinceText($card) }}</span>
                            </span>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    </div>

    <p class="dash-credit">
        {{ __($credit) }}, {{ __('from') }} <a href="{{ $about }}">data.gov.my</a>.
        @if ($stateYears)
            {{ __('For :years, crime by state: :source.', ['years' => implode(' '.__('and').' ', $stateYears), 'source' => __($stateSource)]) }}
        @endif
        {{ __('Up is more crime and down is less.') }}
    </p>

    <script type="application/json" id="dashboard-data">@json($charts)</script>
    <script src="{{ versioned_asset('vendor/chartjs/chart.umd.min.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/dashboard/dashboard.js') }}" defer></script>
@endif
@endsection
