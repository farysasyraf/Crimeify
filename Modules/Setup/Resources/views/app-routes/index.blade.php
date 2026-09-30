@extends('layouts.app')

@section('title', 'Routes')

@section('content')
<div class="page-head">
    <div>
        <h1>Routes</h1>
        <p class="muted">
            {{ $totalCount }} {{ $totalCount === 1 ? 'route' : 'routes' }} in dbo.AppRoutes, added to the app alongside its built-in pages.
            Like every page, they need a login. This page is only for the ADMIN role.
        </p>
    </div>
</div>

@if ($routesCached)
    <div class="alert alert-error" role="alert">
        Laravel's routes are cached, so changes here won't take effect until you run <code>php artisan route:clear</code>.
    </div>
@endif

<div class="route-manager">
    <form method="post" action="{{ $route->exists ? route('routes.update', $route) : route('routes.store') }}" class="card form route-form"
        data-confirm="{{ $route->exists ? 'Are you sure you want to update this route?' : 'Are you sure you want to save this route?' }}"
        data-confirm-kind="{{ $route->exists ? 'update' : 'save' }}">
        @csrf
        @if ($route->exists)
            @method('PUT')
        @endif
        <h2>{{ $route->exists ? 'Edit route' : 'Add route' }}</h2>
        @include('setup::app-routes._form')
        <div @class(['form-actions', 'route-form-actions', 'is-editing' => $route->exists])>
            @if ($route->exists)
                {{-- Opens its own confirmation page without JavaScript; with it, the confirmation box asks, then submits the form below. --}}
                <a class="btn btn-danger-ghost" href="{{ route('routes.delete', $route) }}" data-confirm-form="delete-route" data-confirm="Are you sure you want to delete this route?" data-confirm-kind="delete">Delete</a>
                <button type="submit" class="btn btn-primary">Update</button>
            @else
                <button type="submit" class="btn btn-primary">Save</button>
            @endif
        </div>
    </form>
    @if ($route->exists)
        <form method="post" action="{{ route('routes.destroy', $route) }}" id="delete-route" hidden>
            @csrf
            @method('DELETE')
        </form>
    @endif

    <section class="card routes-panel" aria-labelledby="routes-heading">
        <h2 id="routes-heading" class="visually-hidden">Saved routes</h2>
        <div class="routes-toolbar">
            <form method="get" action="{{ url()->current() }}" class="search" role="search">
                <input type="search" name="search" value="{{ $search }}" placeholder="Search menu, route, controller or function" aria-label="Search routes" />
                <button type="submit" class="btn">Search</button>
                @if ($search !== '')
                    <a class="btn btn-ghost" href="{{ url()->current() }}">Clear</a>
                @endif
            </form>
            <a class="btn btn-primary" href="{{ route('routes.index') }}">+ Add route</a>
        </div>

        @if ($search !== '' && $menus->isEmpty() && $withoutMenu->isEmpty())
            <p class="routes-empty">Nothing matches “{{ $search }}”.</p>
        @elseif ($menus->isEmpty() && $withoutMenu->isEmpty())
            <p class="routes-empty">
                There are no menu links to group routes under.
                <a href="{{ page_url('menu-items/create') }}">Add a menu link</a> first.
            </p>
        @else
            @if ($totalCount === 0)
                <p class="routes-empty">No routes yet. Fill in the form to add the first one; it will show here under its menu.</p>
            @endif
            @include('setup::app-routes._tree', ['items' => $menus])
            @if ($withoutMenu->isNotEmpty())
                <details class="route-group route-group-none" {{ $search !== '' || in_array(0, $openMenuIds) ? 'open' : '' }}>
                    <summary>@include('setup::app-routes._toggle') No menu</summary>
                    <p class="field-hint">Their menu link was deleted. Edit each one to choose a new menu.</p>
                    @include('setup::app-routes._list', ['routes' => $withoutMenu])
                </details>
            @endif
        @endif
    </section>
</div>

<script src="{{ versioned_asset('js/app-routes.js') }}" defer></script>
@endsection
