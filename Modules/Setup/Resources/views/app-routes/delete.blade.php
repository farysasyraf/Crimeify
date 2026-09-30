@extends('layouts.app')

@section('title', 'Delete route')

@section('content')
<a class="back" href="{{ route('routes.edit', $route) }}">← Back to the route</a>
<h1>Delete route</h1>

<form method="post" action="{{ route('routes.destroy', $route) }}" class="card form" data-confirm="Are you sure you want to delete this route?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>
        Are you sure you want to delete this route? It's removed from <code>dbo.AppRoutes</code>, so
        <code>{{ $route->address() }}</code> stops answering {{ $route->HttpMethods === 'ANY' ? 'any' : $route->HttpMethods }} requests.
        The controller and its code aren't changed.
    </p>
    <dl class="details">
        <dt>Menu</dt>
        <dd>{{ $route->menuItem?->Label ?? 'No menu' }}</dd>
        <dt>Route name</dt>
        <dd><code>{{ $route->Path }}</code></dd>
        <dt>Address</dt>
        <dd><code>{{ $route->address() }}</code></dd>
        <dt>Method</dt>
        <dd>{{ $route->HttpMethods }}</dd>
        <dt>Controller</dt>
        <dd>{{ $route->Controller }}</dd>
        <dt>Function</dt>
        <dd>{{ $route->Action }}</dd>
    </dl>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Delete route</button>
        <a class="btn btn-ghost" href="{{ route('routes.edit', $route) }}">Cancel</a>
    </div>
</form>
@endsection
