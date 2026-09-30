@extends('layouts.app')

@section('title', 'Edit role')

@section('content')
<a class="back" href="{{ page_url('roles') }}">← Back to roles</a>
<h1>Edit role</h1>

<form method="post" action="{{ page_url('roles/update', ['role' => $role]) }}" class="card form" data-confirm="Are you sure you want to update this role?" data-confirm-kind="update">
    @csrf
    @method('PUT')
    @include('setup::roles._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a class="btn btn-ghost" href="{{ page_url('roles') }}">Cancel</a>
    </div>
</form>
@endsection
