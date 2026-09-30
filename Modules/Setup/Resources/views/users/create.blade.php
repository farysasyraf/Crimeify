@extends('layouts.app')

@section('title', 'Add user')

@section('content')
<a class="back" href="{{ page_url('users') }}">← Back to users</a>
<h1>Add user</h1>

<form method="post" action="{{ page_url('users/store') }}" class="card form" data-confirm="Are you sure you want to save this user?" data-confirm-kind="save">
    @csrf
    @include('setup::users._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save user</button>
        <a class="btn btn-ghost" href="{{ page_url('users') }}">Cancel</a>
    </div>
</form>
@endsection
