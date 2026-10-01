@extends('layouts.app')

@section('title', 'Crime data')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/crime-data.css') }}" />
@endpush

{{-- The Crime data page, for administrators: the figures behind the map and dashboard, to change in the list, add
     one at a time, or download as Excel and upload back. The totals aren't listed: they're added up from these. --}}
@use('Modules\Map\Support\CrimeData')

@section('content')
@php
    $categories = config('map.crime.categories');
    $name = fn ($figure) => CrimeData::typeLabel($figure->Category, $figure->Type)." in {$figure->District}, {$figure->State}, {$figure->Year}";
    // The Add a figure form and the list's counts both send "crimes": what came back is the Add form's when it says
    // it was sent (form=add), and the list's otherwise.
    $adding = old('form') === 'add';
    $countErrors = $adding ? collect() : collect($errors->getMessages())->filter(fn ($messages, $field) => $field === 'crimes' || str_starts_with($field, 'crimes.'))->flatten();
@endphp

<div class="page-head">
    <div>
        <h1>Crime data</h1>
        <p class="muted">
            The {{ number_format($total) }} figures behind the map and the dashboard: each police district's count of a crime type in a year.
            Change them here, or download them as an Excel file, edit it and upload it back. Every total is added up from these figures,
            so the totals update themselves.
        </p>
    </div>
    <a class="btn btn-success" href="{{ page_url('crime-data/download') }}"><span class="material-icon btn-icon" aria-hidden="true">download</span>Download Excel</a>
</div>

@if ($editCount > 0)
    <div class="alert crime-note" role="note">
        {{ number_format($editCount) }} {{ $editCount === 1 ? 'figure has' : 'figures have' }} been edited here since the last import from data.gov.my.
        Running <code>php artisan map:import-crime</code> again stops rather than replace {{ $editCount === 1 ? 'it' : 'them' }},
        unless it's given <code>--force</code>.
    </div>
@endif

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

<div class="crime-tools">
    {{-- Checked step by step in a dialog that shows each as it goes (upload-progress.js), then its review page. --}}
    <form method="post" action="{{ page_url('crime-data/upload') }}" enctype="multipart/form-data" class="card form crime-tool"
        data-upload-progress data-row="figure" data-rows="figures" data-saved="the saved figures">
        @csrf
        <h2>Upload an Excel file</h2>
        <div class="alert alert-warning-custom" id="upload-warning">
            <span class="material-icon alert-icon" aria-hidden="true">warning</span>
            <span class="alert-text">
                <strong>Warning!</strong><br />
                A file from <strong>Download Excel</strong>, edited. The next page shows what it would change before anything does.
                A figure missing from the file is deleted, so upload the whole file, not part of it.
            </span>
        </div>
        <div class="field">
            <label for="file">Excel file (.xlsx)</label>
            <input type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required aria-describedby="upload-warning" @class(['is-invalid' => $errors->has('file')]) />
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Check the file</button>
        </div>
    </form>

    <form method="post" action="{{ page_url('crime-data/store') }}" class="card form crime-tool" data-confirm="Are you sure you want to add this figure?" data-confirm-kind="save">
        @csrf
        <input type="hidden" name="form" value="add" />
        <h2>Add a figure</h2>
        <div class="crime-tool-fields">
            <div class="field">
                <label for="add-district">Police district</label>
                <select id="add-district" name="district" required data-placeholder="Choose…" @class(['is-invalid' => $errors->has('district')])>
                    <option value="">Choose…</option>
                    @foreach ($pins as $state => $inState)
                        <optgroup label="{{ $state }}">
                            @foreach ($inState as $pin)
                                <option value="{{ $pin->State }}|{{ $pin->Name }}" @selected(old('district') === "{$pin->State}|{$pin->Name}")>{{ $pin->Name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('district')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
            <div class="field">
                <label for="add-type">Crime type</label>
                <select id="add-type" name="type" required data-placeholder="Choose…" @class(['is-invalid' => $errors->has('type')])>
                    <option value="">Choose…</option>
                    @foreach (config('map.crime.types') as $category => $labels)
                        <optgroup label="{{ $categories[$category] }}">
                            @foreach ($labels as $type => $label)
                                <option value="{{ $category }}.{{ $type }}" @selected(old('type') === "{$category}.{$type}")>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('type')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
            <div class="field">
                <label for="add-year">Year</label>
                <input type="number" id="add-year" name="year" value="{{ old('year', $nextYear) }}" min="1900" max="2100" required @class(['is-invalid' => $errors->has('year')]) />
                @error('year')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
            <div class="field">
                <label for="add-crimes">Crimes</label>
                <input type="number" id="add-crimes" name="crimes" value="{{ $adding ? old('crimes') : '' }}" min="0" max="1000000" step="1" required @class(['is-invalid' => $adding && $errors->has('crimes')]) />
                @if ($adding)
                    @error('crimes')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                @endif
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Add</button>
        </div>
    </form>
</div>

<section class="card crime-list" aria-labelledby="figures-heading" data-live-region="figures" data-live-params="state district year type length page">
    <h2 id="figures-heading">Figures</h2>

    <form method="get" action="{{ page_url('crime-data') }}" class="crime-filters" data-crime-filters>
        {{-- The update log's rows per page stays as chosen. --}}
        @if (request()->filled('edits_length'))
            <input type="hidden" name="edits_length" value="{{ $editsLength }}" />
        @endif
        <div>
            <label for="filter-state">State</label>
            <select id="filter-state" name="state" data-placeholder="All states">
                <option value="">All states</option>
                @foreach ($states as $state)
                    <option value="{{ $state }}" @selected($filters['state'] === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-district">Police district</label>
            <select id="filter-district" name="district" data-placeholder="All districts">
                <option value="">All districts</option>
                @foreach ($districts as $state => $inState)
                    <optgroup label="{{ $state }}">
                        @foreach ($inState as $place)
                            <option value="{{ $place->District }}" @selected($filters['district'] === $place->District)>{{ $place->District }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-year">Year</label>
            <select id="filter-year" name="year" data-placeholder="All years">
                <option value="">All years</option>
                @foreach ($years as $year)
                    <option value="{{ $year }}" @selected($filters['year'] === $year)>{{ $year }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-type">Crime type</label>
            <select id="filter-type" name="type" data-placeholder="All types">
                <option value="">All types</option>
                @foreach (config('map.crime.types') as $category => $labels)
                    <optgroup label="{{ $categories[$category] }}">
                        <option value="{{ $category }}" @selected($filters['type'] === $category)>All {{ mb_strtolower($categories[$category]) }}</option>
                        @foreach ($labels as $type => $label)
                            <option value="{{ $category }}.{{ $type }}" @selected($filters['type'] === "{$category}.{$type}")>{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-length">Rows per page</label>
            <select id="filter-length" name="length">
                @foreach ($lengthMenu as $value => $label)
                    <option value="{{ $value }}" @selected($length === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="crime-filter-actions">
            <button type="submit" class="btn" data-filter-submit>Show</button>
            @if ($filtered)
                <a class="btn btn-ghost" href="{{ page_url('crime-data', array_filter(['length' => request()->filled('length') ? $length : null, 'edits_length' => request()->filled('edits_length') ? $editsLength : null], fn ($value) => $value !== null)) }}">Clear</a>
            @endif
        </div>
    </form>

    @if ($figures->isEmpty())
        <p class="crime-empty muted">{{ $filtered ? 'No figures match these choices.' : 'There are no figures yet. Load them with php artisan map:import-crime, or add one above.' }}</p>
    @else
        @if ($countErrors->isNotEmpty())
            <div class="alert alert-error" role="alert">
                <strong>Nothing was saved.</strong>
                <ul>
                    @foreach ($countErrors as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ page_url('crime-data/update') }}" data-confirm="Are you sure you want to save the changed counts?" data-confirm-kind="update" data-crime-counts>
            @csrf
            @method('PUT')
            {{-- How many rows the save sends, first, so the server can tell if PHP dropped any (crime-data.js sends only the changed ones). --}}
            <input type="hidden" name="rows" value="{{ $figures->count() }}" data-rows />

            <div class="table-wrap">
                <table class="table crime-table">
                    <thead>
                        <tr>
                            <th>State</th>
                            <th>Police district</th>
                            <th class="num">Year</th>
                            <th>Crime type</th>
                            <th class="num">Crimes</th>
                            <th class="actions"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($figures as $figure)
                            <tr>
                                <td>{{ $figure->State }}</td>
                                <td>{{ $figure->District }}</td>
                                <td class="num">{{ $figure->Year }}</td>
                                <td>
                                    {{ CrimeData::typeLabel($figure->Category, $figure->Type) }}
                                    <span class="crime-category">{{ $categories[$figure->Category] ?? $figure->Category }}</span>
                                </td>
                                <td class="num">
                                    <input type="hidden" name="original[{{ $figure->Id }}]" value="{{ old("original.{$figure->Id}", $figure->Crimes) }}" />
                                    <input type="number" name="crimes[{{ $figure->Id }}]" value="{{ old("crimes.{$figure->Id}", $figure->Crimes) }}" min="0" max="1000000" step="1" inputmode="numeric"
                                        aria-label="Crimes: {{ $name($figure) }}" @class(['crime-count', 'is-invalid' => $errors->has("crimes.{$figure->Id}")]) />
                                </td>
                                <td class="actions">
                                    {{-- Opens its own confirmation page without JavaScript; with it, the confirmation box asks, then sends the form below the list to this figure's address. --}}
                                    <a class="btn btn-sm btn-danger-ghost" href="{{ page_url('crime-data/delete', ['crimeStat' => $figure]) }}"
                                        data-confirm-form="delete-figure" data-confirm-action="{{ page_url('crime-data/destroy', ['crimeStat' => $figure]) }}"
                                        data-confirm="Are you sure you want to delete {{ $name($figure) }}?" data-confirm-kind="delete">Delete</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="crime-list-foot">
                <div class="crime-save">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <span class="muted" data-unsaved aria-live="polite"></span>
                </div>
                @include('layouts._pager', ['paginator' => $figures, 'label' => 'Pages of figures'])
            </div>
        </form>

        {{-- One delete form for every figure: each Delete sets its address before sending it (confirm.js). --}}
        <form method="post" action="" id="delete-figure" hidden>
            @csrf
            @method('DELETE')
        </form>
    @endif
</section>

{{-- Every edit since the last import, newest first, a page at a time as the figures are, but on its own: its rows per
     page and page are edits_length and edits_page, and the figures' choices stay in the address as they are. --}}
<section class="card crime-edits" aria-labelledby="edits-heading" data-live-region="edits" data-live-params="edits_length edits_page">
    <h2 id="edits-heading">Update Logs by User</h2>
    @if ($edits->isEmpty())
        <p class="muted">None since the last import from data.gov.my.</p>
    @else
        <form method="get" action="{{ page_url('crime-data') }}#edits-heading" class="crime-filters" data-crime-filters>
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
            <div class="crime-filter-actions">
                <button type="submit" class="btn" data-filter-submit>Show</button>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Figure</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($edits as $edit)
                        <tr>
                            <td class="nowrap muted">{{ $edit->CreatedAt->format('d M Y, H:i') }}</td>
                            <td>{{ $edit->UserName }} <span class="crime-category">{{ $edit->Source === 'upload' ? 'from a file' : 'on this page' }}</span></td>
                            <td>{{ CrimeData::typeLabel($edit->Category, $edit->Type) }} in {{ $edit->District }}, {{ $edit->State }}, {{ $edit->Year }}</td>
                            <td class="nowrap">
                                @if ($edit->Action === 'changed')
                                    {{ number_format($edit->OldCrimes) }} → {{ number_format($edit->NewCrimes) }}
                                @elseif ($edit->Action === 'added')
                                    Added: {{ number_format($edit->NewCrimes) }}
                                @else
                                    Deleted (was {{ number_format($edit->OldCrimes) }})
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="crime-list-foot crime-edits-foot">
            @include('layouts._pager', ['paginator' => $edits, 'label' => 'Pages of the update log'])
        </div>
    @endif
</section>

<script src="{{ versioned_asset('modules/map/crime-data.js') }}" defer></script>
@endsection
