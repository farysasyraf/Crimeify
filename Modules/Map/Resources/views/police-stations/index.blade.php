@extends('layouts.app')

@section('title', 'Police stations')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/police-stations.css') }}" />
    <script src="{{ versioned_asset('modules/map/police-stations.js') }}" defer></script>
@endpush

{{-- The police stations the Map page lists under the map, to keep up to date: narrowed by state, a search, or to
     those still without an address or phone number, a page at a time as on the Crime data page. --}}
@section('content')
<div class="page-head">
    <div>
        <h1>Police stations</h1>
        <p class="muted">
            {{ $total }} police {{ $total === 1 ? 'station' : 'stations' }} in dbo.PoliceStations, listed by state under the
            map on the Map page, with their address and phone number.
        </p>
    </div>
    {{-- Starts the new station in the state the list is narrowed to, so it refreshes with the list. --}}
    <a class="btn btn-primary" href="{{ page_url('police-stations/create', $filters['state'] !== '' ? ['state' => $filters['state']] : []) }}" data-live-region="stations-add" data-live-params="state">+ Add police station</a>
</div>

@if ($total > 0 && ($withoutAddress > 0 || $withoutPhone > 0))
    <div class="alert alert-warning-custom" role="note">
        <span class="material-icon alert-icon" aria-hidden="true">info</span>
        <span class="alert-text">
            {{ $withoutAddress }} {{ $withoutAddress === 1 ? 'station has' : 'stations have' }} no address and {{ $withoutPhone }} no phone number yet,
            which the Map page shows as "Not added yet". Tick <strong>Only those missing details</strong> to list them.
        </span>
    </div>
@endif

<section class="card station-list" aria-labelledby="stations-heading" data-live-region="stations" data-live-params="state search missing length page">
    <h2 id="stations-heading" class="visually-hidden">Stations</h2>

    <form method="get" action="{{ page_url('police-stations') }}" class="station-filters" role="search" data-station-filters>
        <div>
            <label for="filter-state">State</label>
            <select id="filter-state" name="state" data-placeholder="All states">
                <option value="">All states</option>
                @foreach (['States' => false, 'Federal territories' => true] as $group => $territory)
                    <optgroup label="{{ $group }}">
                        @foreach (collect(config('map.states'))->where('territory', $territory)->sortBy('name') as $code => $region)
                            <option value="{{ $code }}" @selected($filters['state'] === $code)>{{ $region['name'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-search">Search</label>
            <input type="search" id="filter-search" name="search" value="{{ $filters['search'] }}" placeholder="Name, district or address" />
        </div>
        <div>
            <label for="filter-length">Rows per page</label>
            <select id="filter-length" name="length">
                @foreach ($lengthMenu as $value => $label)
                    <option value="{{ $value }}" @selected($length === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label class="check station-missing">
            <input type="checkbox" name="missing" value="1" @checked($filters['missing']) />
            <span>Only those missing details</span>
        </label>
        <div class="station-filter-actions">
            <button type="submit" class="btn">Show</button>
            @if ($filtered)
                <a class="btn btn-ghost" href="{{ page_url('police-stations', request()->filled('length') ? ['length' => $length] : []) }}">Clear</a>
            @endif
        </div>
    </form>

    @if ($stations->isEmpty())
        <p class="station-empty muted">
            @if ($filtered)
                No police stations match these choices.
            @else
                No police stations yet. Add one above, or load one for each police district with <code>php artisan map:import-stations</code>.
            @endif
        </p>
    @else
        @if ($filtered)
            <p class="station-count muted">{{ $stations->total() }} of {{ $total }} police stations.</p>
        @endif
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="num">No.</th>
                        <th>Police station</th>
                        <th>State</th>
                        <th>Address</th>
                        <th>Phone</th>
                        <th class="actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stations as $station)
                        <tr>
                            <td class="num muted">{{ $stations->firstItem() + $loop->index }}</td>
                            <td>
                                {{ $station->Name }}
                                <span class="station-district muted">{{ $station->District }} police district</span>
                            </td>
                            <td class="nowrap">{{ $station->regionName() }}</td>
                            <td>@if (filled($station->Address)){{ $station->Address }}@else<span class="muted">Not added yet</span>@endif</td>
                            <td class="nowrap">@if (filled($station->Phone)){{ $station->Phone }}@else<span class="muted">Not added yet</span>@endif</td>
                            <td class="actions">
                                <a class="btn btn-sm" href="{{ page_url('police-stations/edit', ['station' => $station]) }}">Edit</a>
                                <a class="btn btn-sm btn-danger-ghost" href="{{ page_url('police-stations/delete', ['station' => $station]) }}">Delete</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="station-list-foot">
            @include('layouts._pager', ['paginator' => $stations, 'label' => 'Pages of police stations'])
        </div>
    @endif
</section>
@endsection
