@extends('layouts.app')

@section('title', 'Edit profile')

@push('head')
    <script src="{{ versioned_asset('js/profile-photo.js') }}" defer></script>
@endpush

{{-- Your own photo and details. Roles are given on the Edit user page, by whoever the Routes page lets. --}}
@section('content')
<h1>Edit profile</h1>

@php($version = $user->photoVersion())
@include('setup::users._details', [
    'mine' => true,
    'allRoles' => null,
    'photoUrl' => $version ? route('profile.photo', ['v' => $version]) : null,
    'storePhotoUrl' => route('profile.photo.store'),
    'destroyPhotoUrl' => route('profile.photo.destroy'),
    'updateUrl' => route('profile.update'),
    'pageUrl' => route('profile'),
])
@endsection
