{{-- "Check an area" (/public/areas): choose up to three police districts to see, or go to one from the list by state.
     For someone deciding where to live or stay: the facts and trends of each, not a score (AreaController). --}}
@extends('layouts.public')

@section('title', __('Check an area'))
@section('description', __('See how crime has changed in a police district, which crimes are most common there, and how it compares with other areas.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/areas.css') }}" />
@endpush

@section('content')
<div class="page-head">
    <div>
        <h1>{{ __('Check an area') }}</h1>
        <p class="muted">{{ __('Thinking of living or staying somewhere? See how crime has changed there, which crimes are most common, and how it compares with other areas.') }}</p>
    </div>
</div>

@if ($regions === [])
    <div class="empty"><p>{{ __('There are no crime figures yet.') }}</p></div>
@else
    <div class="area-page">
        <section class="area-card area-wide" aria-labelledby="area-choose-heading">
            <h2 id="area-choose-heading">{{ __('Choose a police district, or up to three to compare') }}</h2>
            @include('map::areas._picker')
        </section>

        {{-- "Near you" (areas-near.js, nearby.js): the police districts nearest the visitor, from their browser's location,
             which stays in their browser. Shown once the script finds the browser can give one. --}}
        @if ($pins !== [])
            <section class="area-card area-wide area-near" aria-labelledby="area-near-heading" data-near data-turf="{{ $turf }}" data-compare="{{ route('public.areas.compare', $language) }}" hidden>
                <h2 id="area-near-heading">{{ __('Near you') }}</h2>
                <p class="area-sub">{{ __('Find the police districts nearest to where you are, or to a place.') }}</p>
                <button type="button" class="btn btn-sm btn-primary near-button" data-near-button>
                    <span class="material-icon" aria-hidden="true">my_location</span>{{ __('Use my location') }}
                </button>
                <p class="area-note" data-near-privacy>{{ __("Your location stays in your browser: it isn't sent to :app or kept.", ['app' => config('app.name')]) }}</p>
                {{-- Or a place or address, found by the server (PlaceSearch). --}}
                <form class="near-search" data-place-search data-places="{{ route('public.places') }}">
                    <label for="near-place">{{ __('Or a place or address') }}</label>
                    <div class="near-search-row">
                        <input type="search" id="near-place" name="q" minlength="3" maxlength="100" required autocomplete="off" placeholder="{{ __('Like Taman Melawati, Jalan Ampang or 47500') }}" />
                        <button type="submit" class="btn btn-sm">{{ __('Search') }}</button>
                    </div>
                </form>
                <ul class="near-places" data-place-results hidden></ul>
                <div class="near-results" data-near-results aria-live="polite"></div>
                <script type="application/json" data-district-pins>@json($pins)</script>
            </section>
        @endif

        <section class="area-card area-wide" aria-labelledby="area-browse-heading">
            <h2 id="area-browse-heading">{{ __('Every police district, by state') }}</h2>
            <div class="area-browse">
                @foreach ($regions as $region => $districts)
                    <details>
                        <summary>{{ $region }} <span>({{ count($districts) }})</span></summary>
                        <ul>
                            @foreach ($districts as $district)
                                @php([$state, $name] = explode('/', $district['path'], 2))
                                <li><a href="{{ route('public.areas.show', ['region' => $state, 'district' => $name] + $language) }}">{{ $district['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
        </section>

        @include('map::areas._about')
    </div>

    <script src="{{ versioned_asset('modules/map/nearby.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/map/areas-near.js') }}" defer></script>
@endif
@endsection
