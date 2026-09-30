@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p class="muted">{{ $totalCount }} {{ $totalCount === 1 ? 'user' : 'users' }} in dbo.Users</p>
    </div>
    <form method="get" action="{{ page_url('users') }}" class="search" role="search">
        <input type="search" name="search" value="{{ $search }}" placeholder="Search name, username or email" aria-label="Search users" />
        <button type="submit" class="btn">Search</button>
        @if ($search !== '')
            <a class="btn btn-ghost" href="{{ page_url('users') }}">Clear</a>
        @endif
    </form>
</div>

@if ($databaseError !== null)
    <div class="alert alert-error" role="alert">
        <strong>Can't reach the database.</strong> Check that SQL Server is running and the
        <code>DB_*</code> settings in <code>.env</code> are correct.
        <div class="alert-detail">{{ $databaseError }}</div>
    </div>
@elseif ($users->isEmpty())
    <div class="empty">
        @if ($search === '')
            <p>No users yet.</p>
            <a class="btn btn-primary" href="{{ page_url('users/create') }}">Add the first user</a>
        @else
            <p>No users match “{{ $search }}”.</p>
        @endif
    </div>
@else
    <div class="card table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th class="num">No.</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Roles</th>
                    <th>Created</th>
                    <th class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="num muted">{{ $loop->iteration }}</td>
                        <td>{{ $user->Name }}</td>
                        <td>{{ $user->Username }}</td>
                        <td><a href="mailto:{{ $user->Email }}">{{ $user->Email }}</a></td>
                        <td>
                            <div class="badges">
                                @forelse ($user->roles as $role)
                                    <span class="badge badge-role">{{ $role->Name }}</span>
                                @empty
                                    <span class="muted">None</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="muted nowrap">{{ $user->CreatedAt?->format('d M Y, H:i') }}</td>
                        <td class="actions">
                            <a class="btn btn-sm" href="{{ page_url('users/edit', ['user' => $user]) }}">Edit</a>
                            <a class="btn btn-sm btn-danger-ghost" href="{{ page_url('users/delete', ['user' => $user]) }}">Delete</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- The users log, only for ADMIN (the controller leaves $log null for anyone else): who is online now and when each
     user was last online, from RecordLastSeen and logging out, in Malaysia time (config('app.timezone')). --}}
@if ($log !== null)
    @php($onlineCount = $log->filter->isOnline()->count())
    <section class="card users-log" aria-labelledby="users-log-heading">
        <div class="users-log-head">
            <h2 id="users-log-heading">Users log</h2>
            <p class="muted">
                {{ $onlineCount }} of {{ $log->count() }} {{ $log->count() === 1 ? 'user' : 'users' }} online now.
                Online is having opened a page in the last {{ \App\Models\User::OnlineMinutes }} minutes without logging out since.
                Only the ADMIN role sees this.
            </p>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Status</th>
                        <th>Last online</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($log as $entry)
                        <tr>
                            <td>
                                {{ $entry->Name }}
                                <span class="users-log-email muted">{{ $entry->Email }}</span>
                            </td>
                            <td>
                                @if ($entry->isOnline())
                                    <span class="status status-online"><span class="status-dot" aria-hidden="true"></span>Online</span>
                                @else
                                    <span class="status status-offline"><span class="status-dot" aria-hidden="true"></span>Offline</span>
                                @endif
                            </td>
                            <td class="nowrap">
                                @if ($entry->isOnline())
                                    Now
                                @elseif ($entry->LastSeenAt)
                                    <time datetime="{{ $entry->LastSeenAt->toIso8601String() }}">{{ $entry->LastSeenAt->diffForHumans() }}</time>
                                    <span class="muted">· {{ $entry->LastSeenAt->format('d M Y, H:i') }}</span>
                                @else
                                    <span class="muted">Never</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
