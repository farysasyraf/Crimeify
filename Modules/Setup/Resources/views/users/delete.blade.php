@extends('layouts.app')

@section('title', 'Delete user')

@section('content')
<a class="back" href="{{ page_url('users') }}">← Back to users</a>
<h1>Delete user</h1>

<form method="post" action="{{ page_url('users/destroy', ['user' => $user]) }}" class="card form" data-confirm="Are you sure you want to delete this user?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>This permanently removes the user from <code>dbo.Users</code>. It can't be undone.</p>
    <dl class="details">
        <dt>ID</dt>
        <dd>{{ $user->Id }}</dd>
        <dt>Name</dt>
        <dd>{{ $user->Name }}</dd>
        <dt>Email</dt>
        <dd>{{ $user->Email }}</dd>
        <dt>Roles</dt>
        <dd>{{ $user->roles->pluck('Name')->sort()->join(', ') ?: 'None' }}</dd>
        <dt>Created</dt>
        <dd>{{ $user->CreatedAt?->format('d M Y, H:i') }}</dd>
    </dl>
    @if ($user->is(auth()->user()) || $user->isLastAdmin())
        <div class="alert alert-error" role="alert">
            @if ($user->is(auth()->user()))
                You can't delete the account you're logged in with.
            @else
                {{ $user->Name }} is the only user with the ADMIN role, which opens the Routes page, so they can't be deleted. Give the role to someone else first.
            @endif
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ page_url('users') }}">Back to users</a>
        </div>
    @else
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Delete user</button>
            <a class="btn btn-ghost" href="{{ page_url('users') }}">Cancel</a>
        </div>
    @endif
</form>
@endsection
