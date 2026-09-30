@extends('layouts.app')

@section('title', 'Add police station')

@section('content')
<a class="back" href="{{ page_url('police-stations') }}">← Back to police stations</a>
<h1>Add police station</h1>

<form method="post" action="{{ page_url('police-stations/store') }}" class="card form" data-confirm="Are you sure you want to save this police station?" data-confirm-kind="save">
    @csrf
    @include('map::police-stations._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save police station</button>
        <a class="btn btn-ghost" href="{{ page_url('police-stations') }}">Cancel</a>
    </div>
</form>
@endsection
