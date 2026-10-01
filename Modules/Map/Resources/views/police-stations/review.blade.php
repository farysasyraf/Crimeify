@extends('layouts.app')

@section('title', 'Check the upload')

{{-- The upload review's look is the Crime data page's: the counts in boxes, the changes as coloured badges. --}}
@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/crime-data.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/map/police-stations.css') }}" />
@endpush

{{-- What an uploaded Excel file would change in the police stations, before anything does: apply it, or cancel. A
     changed station shows only what changes in it, before and after (_change.blade.php). --}}
@section('content')
@php
    $words = ['changed' => 'Changed', 'added' => 'Added', 'deleted' => 'Deleted'];
    $counts = $changes->countBy('action');
@endphp

<a class="back" href="{{ page_url('police-stations') }}">← Back to police stations</a>
<h1>Check the upload</h1>
<p class="muted">
    {{ $file }} has {{ number_format($stationCount) }} police {{ $stationCount === 1 ? 'station' : 'stations' }}. Applying it makes the changes below.
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
        {{ number_format($counts['deleted']).($one ? ' police station isn\'t' : ' police stations aren\'t').' in the file, so applying it deletes '.($one ? 'it' : 'them').'.' }}
        If the file only has some of the stations, cancel, then download the whole file and edit that.
    </div>
@endif

@foreach ($warnings as $warning)
    <div class="alert crime-note" role="note">{{ $warning }}</div>
@endforeach

<div class="card table-wrap crime-review">
    <table class="table station-review">
        <thead>
            <tr>
                <th>Change</th>
                <th>Police station</th>
                <th>What changes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($changes as $change)
                @php $station = $change['new'] ?? $change['old']; @endphp
                <tr>
                    <td><span class="badge crime-badge-{{ $change['action'] }}">{{ $words[$change['action']] }}</span></td>
                    <td>
                        {{ $station['Name'] }}
                        <span class="station-district muted">{{ $station['District'] }} police district · {{ config("map.states.{$station['Region']}.name", $station['Region']) }}</span>
                    </td>
                    <td>@include('map::police-stations._change', ['action' => $change['action'], 'old' => $change['old'], 'new' => $change['new']])</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="form-actions crime-review-actions">
    <form method="post" action="{{ page_url('police-stations/apply', ['upload' => $token]) }}" data-confirm="Are you sure you want to apply these changes to the police stations?" data-confirm-kind="update">
        @csrf
        <button type="submit" class="btn btn-primary">Apply the changes</button>
    </form>
    <form method="post" action="{{ page_url('police-stations/cancel', ['upload' => $token]) }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-ghost">Cancel</button>
    </form>
</div>
@endsection
