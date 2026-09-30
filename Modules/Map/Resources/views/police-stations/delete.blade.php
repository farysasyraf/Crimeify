@extends('layouts.app')

@section('title', 'Delete police station')

@section('content')
<a class="back" href="{{ page_url('police-stations') }}">← Back to police stations</a>
<h1>Delete police station</h1>

<form method="post" action="{{ page_url('police-stations/destroy', ['station' => $station]) }}" class="card form" data-confirm="Are you sure you want to delete this police station?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>
        This permanently removes the police station from <code>dbo.PoliceStations</code>, so the Map page no longer lists it.
        The police district's pin and crime figures stay.
    </p>
    <dl class="details">
        <dt>Name</dt>
        <dd>{{ $station->Name }}</dd>
        <dt>State</dt>
        <dd>{{ $station->regionName() }}</dd>
        <dt>Police district</dt>
        <dd>{{ $station->District }}</dd>
        <dt>Address</dt>
        <dd>{{ $station->Address ?: '—' }}</dd>
        <dt>Phone</dt>
        <dd>{{ $station->Phone ?: '—' }}</dd>
    </dl>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Delete police station</button>
        <a class="btn btn-ghost" href="{{ page_url('police-stations') }}">Cancel</a>
    </div>
</form>
@endsection
