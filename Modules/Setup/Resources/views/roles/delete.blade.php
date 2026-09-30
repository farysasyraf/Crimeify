@extends('layouts.app')

@section('title', 'Delete role')

@section('content')
<a class="back" href="{{ page_url('roles') }}">← Back to roles</a>
<h1>Delete role</h1>

<form method="post" action="{{ page_url('roles/destroy', ['role' => $role]) }}" class="card form" data-confirm="Are you sure you want to delete this role?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>
        This permanently removes the role from <code>dbo.Roles</code>.
        @if ($role->users_count > 0)
            {{ $role->users_count }} {{ $role->users_count === 1 ? 'user has' : 'users have' }} this role and will lose it. The users themselves aren't deleted.
        @else
            No users have this role.
        @endif
    </p>
    @if ($role->menu_items_count > 0)
        <p>
            {{ $role->menu_items_count }} menu {{ $role->menu_items_count === 1 ? 'link is' : 'links are' }} shown to this role.
            A link shown only to this role will be hidden from everyone until you choose new roles for it in Manage menu.
        </p>
    @endif
    <dl class="details">
        <dt>Name</dt>
        <dd>{{ $role->Name }}</dd>
        <dt>Description</dt>
        <dd>{{ $role->Description ?: '—' }}</dd>
        <dt>Users</dt>
        <dd>{{ $role->users_count }}</dd>
        <dt>Menu links</dt>
        <dd>{{ $role->menu_items_count }}</dd>
    </dl>
    @if ($role->isAdmin())
        <div class="alert alert-error" role="alert">The ADMIN role can't be deleted: it's the role that opens the Routes page.</div>
        <div class="form-actions">
            <a class="btn" href="{{ page_url('roles') }}">Back to roles</a>
        </div>
    @else
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Delete role</button>
            <a class="btn btn-ghost" href="{{ page_url('roles') }}">Cancel</a>
        </div>
    @endif
</form>
@endsection
