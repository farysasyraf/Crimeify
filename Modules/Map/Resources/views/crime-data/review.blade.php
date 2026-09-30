@extends('layouts.app')

@section('title', 'Check the upload')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/crime-data.css') }}" />
@endpush

@use('Modules\Map\Support\CrimeData')

{{-- What an uploaded Excel file would change, before anything does: apply it, or cancel. --}}
@section('content')
@php
    // The most changes listed; the counts above the list cover them all.
    $listed = 200;
    $counts = $changes->countBy('action');
    $words = ['changed' => 'Changed', 'added' => 'Added', 'deleted' => 'Deleted'];
@endphp

<a class="back" href="{{ page_url('crime-data') }}">← Back to crime data</a>
<h1>Check the upload</h1>
<p class="muted">
    {{ $file }} has {{ number_format($figureCount) }} figures. Applying it makes the changes below and adds up the totals above them again.
    Nothing has changed yet.
</p>

<ul class="crime-summary">
    @foreach ($words as $action => $word)
        <li class="crime-summary-{{ $action }}"><strong>{{ number_format($counts[$action] ?? 0) }}</strong> {{ mb_strtolower($word) }}</li>
    @endforeach
</ul>

@if (($counts['deleted'] ?? 0) > 0)
    @php $one = $counts['deleted'] === 1; @endphp
    <div class="alert alert-error" role="alert">
        {{ number_format($counts['deleted']).($one ? ' figure isn\'t' : ' figures aren\'t').' in the file, so applying it deletes '.($one ? 'it' : 'them').'.' }}
        If the file only has some of the figures, cancel, then download the whole file and edit that.
    </div>
@endif

@foreach ($warnings as $warning)
    <div class="alert crime-note" role="note">{{ $warning }}</div>
@endforeach

<div class="card table-wrap crime-review">
    <table class="table">
        <thead>
            <tr>
                <th>Change</th>
                <th>State</th>
                <th>Police district</th>
                <th class="num">Year</th>
                <th>Crime type</th>
                <th class="num">Before</th>
                <th class="num">After</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($changes->take($listed) as $change)
                <tr>
                    <td><span class="badge crime-badge-{{ $change['action'] }}">{{ $words[$change['action']] }}</span></td>
                    <td>{{ $change['state'] }}</td>
                    <td>{{ $change['district'] }}</td>
                    <td class="num">{{ $change['year'] }}</td>
                    <td>{{ CrimeData::typeLabel($change['category'], $change['type']) }}</td>
                    <td class="num muted">{{ $change['old'] === null ? '–' : number_format($change['old']) }}</td>
                    <td class="num">{{ $change['new'] === null ? '–' : number_format($change['new']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@if ($changes->count() > $listed)
    <p class="muted">And {{ number_format($changes->count() - $listed) }} more changes, not listed here.</p>
@endif

<div class="form-actions crime-review-actions">
    <form method="post" action="{{ page_url('crime-data/apply', ['upload' => $token]) }}" data-confirm="Are you sure you want to apply these changes to the crime figures?" data-confirm-kind="update">
        @csrf
        <button type="submit" class="btn btn-primary">Apply the changes</button>
    </form>
    <form method="post" action="{{ page_url('crime-data/cancel', ['upload' => $token]) }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-ghost">Cancel</button>
    </form>
</div>
@endsection
