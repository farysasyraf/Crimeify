@extends('layouts.app')

@section('title', 'Edit police station')

@section('content')
<a class="back" href="{{ page_url('police-stations') }}">← Back to police stations</a>
<h1>Edit police station</h1>

<form method="post" action="{{ page_url('police-stations/update', ['station' => $station]) }}" class="card form" data-confirm="Are you sure you want to update this police station?" data-confirm-kind="update">
    @csrf
    @method('PUT')
    @include('map::police-stations._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a class="btn btn-ghost" href="{{ page_url('police-stations') }}">Cancel</a>
    </div>
</form>
@endsection
