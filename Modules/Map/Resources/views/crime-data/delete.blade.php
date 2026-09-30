@extends('layouts.app')

@section('title', 'Delete figure')

{{-- Asks before deleting a figure when JavaScript is off; with it, the Crime data page's confirmation box asks instead. --}}
@section('content')
<a class="back" href="{{ page_url('crime-data') }}">← Back to crime data</a>
<h1>Delete figure</h1>

<form method="post" action="{{ page_url('crime-data/destroy', ['crimeStat' => $figure]) }}" class="card form" data-confirm="Are you sure you want to delete {{ $name }}?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>
        Are you sure you want to delete {{ $name }}? The totals above it, for {{ $figure->District }}, {{ $figure->State }} and Malaysia,
        are added up again without it. The deletion is listed under Latest edits.
    </p>
    <dl class="details">
        <dt>State</dt>
        <dd>{{ $figure->State }}</dd>
        <dt>Police district</dt>
        <dd>{{ $figure->District }}</dd>
        <dt>Year</dt>
        <dd>{{ $figure->Year }}</dd>
        <dt>Crime type</dt>
        <dd>{{ \Modules\Map\Support\CrimeData::typeLabel($figure->Category, $figure->Type) }}</dd>
        <dt>Crimes</dt>
        <dd>{{ number_format($figure->Crimes) }}</dd>
    </dl>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Delete figure</button>
        <a class="btn btn-ghost" href="{{ page_url('crime-data') }}">Cancel</a>
    </div>
</form>
@endsection
