@extends('layouts.app')

@section('title', 'Edit user')

@push('head')
    <script src="{{ versioned_asset('js/profile-photo.js') }}" defer></script>
@endpush

@section('content')
<a class="back" href="{{ page_url('users') }}">← Back to users</a>
<h1>Edit user <span class="muted">#{{ $user->Id }}</span></h1>

@php($version = $user->photoVersion())
@include('setup::users._details', [
    'mine' => false,
    'allRoles' => $roles,
    'photoUrl' => $version ? page_url('users/photo', ['user' => $user, 'v' => $version]) : null,
    'storePhotoUrl' => page_url('users/store-photo', ['user' => $user]),
    'destroyPhotoUrl' => page_url('users/destroy-photo', ['user' => $user]),
    'updateUrl' => page_url('users/update', ['user' => $user]),
    'pageUrl' => page_url('users/edit', ['user' => $user]),
])
@endsection
