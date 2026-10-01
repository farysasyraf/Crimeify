@extends('layouts.app')

@section('title', 'Police stations')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/police-stations.css') }}" />
    <script src="{{ versioned_asset('modules/map/police-stations.js') }}" defer></script>
@endpush

{{-- The police stations the Map page lists under the map, to keep up to date: narrowed by state, a search, or to
     those still without an address or phone number, a page at a time as on the Crime data page. As there, they can
     be downloaded as an Excel file, edited and uploaded back: the next page shows what would change first. And as
     there, the update log under the list says who changed what, and when. --}}
@section('content')
<div class="page-head">
    <div>
        <h1>Police stations</h1>
        <p class="muted">
            {{ $total }} police {{ $total === 1 ? 'station' : 'stations' }} in dbo.PoliceStations, listed by state under the
            map on the Map page, with their address and phone number.
        </p>
    </div>
    <div class="station-head-actions">
        <a class="btn btn-success" href="{{ page_url('police-stations/download') }}"><span class="material-icon btn-icon" aria-hidden="true">download</span>Download Excel</a>
        {{-- Starts the new station in the state the list is narrowed to, so it refreshes with the list. --}}
        <a class="btn btn-primary" href="{{ page_url('police-stations/create', $filters['state'] !== '' ? ['state' => $filters['state']] : []) }}" data-live-region="stations-add" data-live-params="state">+ Add police station</a>
    </div>
</div>

@error('file')
    <div class="alert alert-error" role="alert">
        <strong>The file wasn't used, and nothing changed.</strong>
        <ul>
            @foreach ($errors->get('file') as $problem)
                <li>{{ $problem }}</li>
            @endforeach
        </ul>
    </div>
@enderror

@if ($total > 0 && ($withoutAddress > 0 || $withoutPhone > 0))
    <div class="alert alert-warning-custom" role="note">
        <span class="material-icon alert-icon" aria-hidden="true">info</span>
        <span class="alert-text">
            {{ $withoutAddress }} {{ $withoutAddress === 1 ? 'station has' : 'stations have' }} no address and {{ $withoutPhone }} no phone number yet,
            which the Map page shows as "Not added yet". Tick <strong>Only those missing details</strong> to list them.
        </span>
    </div>
@endif

{{-- Checked step by step in a dialog that shows each as it goes (upload-progress.js), then its review page. --}}
<form method="post" action="{{ page_url('police-stations/upload') }}" enctype="multipart/form-data" class="card form station-upload"
    data-upload-progress data-row="police station" data-rows="police stations" data-saved="the saved police stations">
    @csrf
    <h2>Upload an Excel file</h2>
    <div class="alert alert-warning-custom" id="upload-warning">
        <span class="material-icon alert-icon" aria-hidden="true">warning</span>
        <span class="alert-text">
            <strong>Warning!</strong><br />
            A file from <strong>Download Excel</strong>, edited. The next page shows what it would change before anything does.
            A station missing from the file is deleted, so upload the whole file, not part of it.
        </span>
    </div>
    <div class="station-upload-row">
        <div class="field">
            <label for="file">Excel file (.xlsx)</label>
            <input type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required aria-describedby="upload-warning" @class(['is-invalid' => $errors->has('file')]) />
        </div>
        <button type="submit" class="btn btn-primary">Check the file</button>
    </div>
</form>

<section class="card station-list" aria-labelledby="stations-heading" data-live-region="stations" data-live-params="state search missing length page">
    <h2 id="stations-heading" class="visually-hidden">Stations</h2>

    <form method="get" action="{{ page_url('police-stations') }}" class="station-filters" role="search" data-station-filters>
        @if (request()->filled('edits_length'))
            <input type="hidden" name="edits_length" value="{{ $editsLength }}" />
        @endif
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
                <a class="btn btn-ghost" href="{{ page_url('police-stations', array_filter(['length' => request()->filled('length') ? $length : null, 'edits_length' => request()->filled('edits_length') ? $editsLength : null], fn ($value) => $value !== null)) }}">Clear</a>
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

{{-- Every change to the stations, newest first, a page at a time as the stations are, but on its own: its rows per
     page and page are edits_length and edits_page, and the list's choices stay in the address as they are. --}}
<section class="card station-edits" aria-labelledby="edits-heading" data-live-region="edits" data-live-params="edits_length edits_page">
    <h2 id="edits-heading">Update Logs by User</h2>
    @if ($edits->isEmpty())
        <p class="muted">None yet. Each station added, edited or deleted here, one at a time or from a file, is listed with who did it and when.</p>
    @else
        <form method="get" action="{{ page_url('police-stations') }}#edits-heading" class="station-filters" data-station-filters>
            @foreach (request()->query() as $key => $value)
                @if (is_string($value) && ! in_array($key, ['edits_length', 'edits_page'], true))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
                @endif
            @endforeach
            <div>
                <label for="edits-length">Rows per page</label>
                <select id="edits-length" name="edits_length">
                    @foreach ($lengthMenu as $value => $label)
                        <option value="{{ $value }}" @selected($editsLength === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="station-filter-actions">
                <button type="submit" class="btn" data-filter-submit>Show</button>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table station-log">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Police station</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($edits as $edit)
                        @php $details = $edit->station(); @endphp
                        <tr>
                            <td class="nowrap muted">{{ $edit->CreatedAt->format('d M Y, H:i') }}</td>
                            <td>{{ $edit->UserName }} <span class="station-source muted">{{ $edit->Source === 'upload' ? 'from a file' : 'on this page' }}</span></td>
                            <td>
                                {{ $details['Name'] }}
                                <span class="station-district muted">{{ $details['District'] }} police district · {{ config("map.states.{$details['Region']}.name", $details['Region']) }}</span>
                            </td>
                            <td>
                                @if ($edit->Action !== 'changed')
                                    <span class="station-edit-action">{{ $edit->Action === 'added' ? 'Added' : 'Deleted' }}</span>
                                @endif
                                @include('map::police-stations._change', ['action' => $edit->Action, 'old' => $edit->OldDetails, 'new' => $edit->NewDetails])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="station-list-foot">
            @include('layouts._pager', ['paginator' => $edits, 'label' => 'Pages of the update log'])
        </div>
    @endif
</section>
@endsection
