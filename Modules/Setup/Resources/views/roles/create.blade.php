@extends('layouts.app')

@section('title', 'Add role')

@section('content')
<a class="back" href="{{ page_url('roles') }}">← Back to roles</a>
<h1>Add role</h1>

<form method="post" action="{{ page_url('roles/store') }}" class="card form" data-confirm="Are you sure you want to save this role?" data-confirm-kind="save">
    @csrf
    @include('setup::roles._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save role</button>
        <a class="btn btn-ghost" href="{{ page_url('roles') }}">Cancel</a>
    </div>
</form>
@endsection
