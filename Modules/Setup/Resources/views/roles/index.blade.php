@extends('layouts.app')

@section('title', 'Roles')

@section('content')
<div class="page-head">
    <div>
        <h1>Roles</h1>
        <p class="muted">Roles you can give to users on the Edit user page, stored in dbo.Roles.</p>
    </div>
    <a class="btn btn-primary" href="{{ page_url('roles/create') }}">+ Add role</a>
</div>

@if ($roles->isEmpty())
    <div class="empty">
        <p>No roles yet.</p>
        <a class="btn btn-primary" href="{{ page_url('roles/create') }}">Add the first role</a>
    </div>
@else
    <div class="card table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th class="num">No.</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="num">Users</th>
                    <th class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr>
                        <td class="num muted">{{ $loop->iteration }}</td>
                        <td>{{ $role->Name }}</td>
                        <td class="muted">{{ $role->Description }}</td>
                        <td class="num">{{ $role->users_count }}</td>
                        <td class="actions">
                            <a class="btn btn-sm" href="{{ page_url('roles/edit', ['role' => $role]) }}">Edit</a>
                            <a class="btn btn-sm btn-danger-ghost" href="{{ page_url('roles/delete', ['role' => $role]) }}">Delete</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
