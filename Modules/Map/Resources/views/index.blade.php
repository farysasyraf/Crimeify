{{-- In the app for logged-in users, and at /public/map for everyone, in the public pages' layout. --}}
@extends($public ? 'layouts.public' : 'layouts.app')

@section('title', __('Map'))

{{-- The map fills the page between the bar and the footer. --}}
@section('main-class', 'main-full')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet-markercluster/MarkerCluster.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet-markercluster/MarkerCluster.Default.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/map.css') }}" />
@endpush

{{-- On a phone, "Find a police station" asks with SweetAlert2 whether to open a station in Google Maps or Waze. The
     app's layout has it already; the public pages' doesn't. --}}
@if ($public)
    @push('head')
        <script src="{{ versioned_asset('js/vendor/sweetalert2.all.min.js') }}" defer></script>
    @endpush
@endif

{{-- Malaysia's states and federal territories on a Leaflet map (map.js) that fills the page, with cards over it, as in
     the Crimeify mockup: at the top, a search for a state, police district or police station; and down the left, the
     year and the chosen region's figures, the regions as buttons that pick one the same way as the map, so every
     region can be chosen without a mouse, and "Find a police station". The right is left to the map, which frames
     Malaysia in what the cards leave clear. Each police district has a pin whose popup gives its crime figures for
     the chosen year. On a narrow screen, the cards go under the map, and only the search stays on it (map.css). --}}
@section('content')
<div class="page-head visually-hidden">
    <h1>{{ __('Crime Visualization Map') }}</h1>
    <p>
        {{ __("Malaysia's 13 states and 3 federal territories, with a pin for each police district. Click a pin for the district's crime figures, or a state for its totals. Numbered circles group nearby pins; click one to zoom in.") }}
    </p>
</div>

<div class="map-stage">
    {{-- A state, police district or police station by name (map.js), which needs JavaScript, as the map does. --}}
    <form class="map-search" role="search" data-map-search data-map-cover="top" hidden>
        <span class="material-icon map-search-pin" aria-hidden="true">location_on</span>
        <label for="map-search" class="visually-hidden">{{ __('Search the map') }}</label>
        <input type="search" id="map-search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="map-search-results"
            autocomplete="off" spellcheck="false" placeholder="{{ __('Search a state, police district or station') }}" />
        <button type="submit" class="map-search-go" aria-label="{{ __('Search') }}"><span class="material-icon" aria-hidden="true">search</span></button>
        <ul class="map-search-results" id="map-search-results" role="listbox" aria-label="{{ __('Search the map') }}" hidden></ul>
        <p class="map-search-none" aria-live="polite" data-map-search-none></p>
    </form>

    {{-- Down the left: the year and the chosen region's figures, the regions, and "Find a police station". Before the
         map, so the keyboard reaches them before its many pins. --}}
    <div class="map-side" data-map-cover="left">
        <section class="card state-panel" aria-labelledby="state-panel-heading">
            @if (! $crimeUrl)
                <p class="crime-note">
                    Crime figures appear once the <code>map/crime</code> route is added on the Routes page, with controller
                    <code>MapController</code> and function <code>crime</code>.
                </p>
            @elseif (! $years)
                {{-- How to load them is for the app's administrators, not the public. --}}
                <p class="crime-note">
                    {{ __('There are no crime figures yet.') }}@unless ($public) Load them from data.gov.my with <code>php artisan map:import-crime</code>.@endunless
                </p>
            @else
                <div class="crime-year">
                    <label for="crime-year">{{ __('Crime figures for') }}</label>
                    <select id="crime-year" data-crime-year>
                        @foreach (array_reverse($years) as $year)
                            <option value="{{ $year }}" @selected($loop->first)>{{ $year }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="state-details" aria-live="polite" data-state-details>
                <h2 id="state-panel-heading">Malaysia</h2>
                <p class="muted">{{ __('Choose a state on the map or from the list.') }}</p>
            </div>
            <button type="button" class="btn btn-sm" data-show-all hidden>{{ __('Show all of Malaysia') }}</button>
        </section>

        {{-- Every region as a button; choosing one picks it on the map too. --}}
        <aside class="card state-picker" aria-label="{{ __('States and federal territories') }}">
            @foreach (['States' => $states, 'Federal territories' => $territories] as $heading => $regions)
                <div class="state-picker-group">
                    <h2 class="state-list-heading">{{ __($heading) }}</h2>
                    <ul class="state-list">
                        @foreach ($regions as $region)
                            <li>
                                <button type="button" class="state-choice" data-state="{{ $region['code'] }}" data-name="{{ $region['name'] }}" data-kind="{{ $region['territory'] ? __('Federal territory') : __('State') }}" aria-pressed="false">{{ $region['name'] }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </aside>

        {{-- A state, then one of its police stations, for the station's address and phone number (station-finder.js).
             The stations come with the page, so choosing one doesn't wait on the server. --}}
        <section class="card station-finder" aria-labelledby="station-finder-heading">
            <h2 id="station-finder-heading">{{ __('Find a police station') }}</h2>
            <p class="muted station-finder-intro">{{ __('Choose a state, then one of its police stations, for its address and phone number.') }}</p>
            @if ($stations === [])
                <p class="station-note">
                    {{ __('No police stations are listed yet.') }}@unless ($public) Add them on the Police stations page, or load one for each police district with <code>php artisan map:import-stations</code>.@endunless
                </p>
            @else
                <script type="application/json" data-station-list>@json($stations)</script>
                <div class="station-finder-fields">
                    <div class="field">
                        <label for="station-state">{{ __('State') }}</label>
                        <select id="station-state" data-station-state data-placeholder="{{ __('Choose a state') }}">
                            <option value=""></option>
                            @foreach (['States' => $states, 'Federal territories' => $territories] as $heading => $regions)
                                <optgroup label="{{ __($heading) }}">
                                    @foreach ($regions as $region)
                                        @isset($stations[$region['code']])
                                            <option value="{{ $region['code'] }}">{{ $region['name'] }}</option>
                                        @endisset
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="station-choice">{{ __('Police station') }}</label>
                        <select id="station-choice" data-station-choice data-placeholder="{{ __('Choose a police station') }}" disabled aria-describedby="station-hint">
                            <option value=""></option>
                        </select>
                        <span class="field-hint" id="station-hint" data-station-hint>{{ __('Choose a state first.') }}</span>
                    </div>
                </div>
                <div class="station-details" data-station-details aria-live="polite" hidden></div>
                <noscript><p class="state-map-note">{{ __('Finding a police station needs JavaScript turned on.') }}</p></noscript>
            @endif
        </section>
    </div>

    <div class="state-map" id="state-map" data-boundaries="{{ $boundaries }}"
        @if ($crimeUrl && $years) data-crime="{{ $crimeUrl }}" @endif
        role="region" aria-label="{{ __("Map of Malaysia's states and police districts") }}">
        <noscript><p class="state-map-note">{{ __('The map needs JavaScript turned on.') }}</p></noscript>
    </div>
    <p class="state-map-note" data-map-status hidden></p>
</div>

<script src="{{ versioned_asset('vendor/leaflet/leaflet.js') }}" defer></script>
<script src="{{ versioned_asset('vendor/leaflet-markercluster/leaflet.markercluster.js') }}" defer></script>
<script src="{{ versioned_asset('modules/map/map.js') }}" defer></script>
<script src="{{ versioned_asset('modules/map/station-finder.js') }}" defer></script>
@endsection
