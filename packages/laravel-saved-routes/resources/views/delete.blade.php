@extends(config('saved-routes.admin.layout', 'saved-routes::layout'))

@section('title', 'Delete route')

@section(config('saved-routes.admin.section', 'content'))
@include('saved-routes::_styles')
<div class="saved-routes">
    <p><a href="{{ route('saved-routes.edit', $route) }}">← Back to the route</a></p>
    <h1>Delete route</h1>

    <form method="post" action="{{ route('saved-routes.destroy', $route) }}" class="sr-card">
        @csrf
        @method('DELETE')
        <p>
            Are you sure you want to delete this route? <code>{{ $route->address() }}</code> stops answering
            {{ $route->method === 'ANY' ? 'any' : $route->method }} requests. The controller and its code aren't changed.
        </p>
        <dl class="sr-details">
            <dt>Route name</dt>
            <dd><code>{{ $route->path }}</code></dd>
            <dt>Address</dt>
            <dd><code>{{ $route->address() }}</code></dd>
            <dt>Method</dt>
            <dd>{{ $route->method }}</dd>
            <dt>Controller</dt>
            <dd>{{ $route->controller }}</dd>
            <dt>Function</dt>
            <dd>{{ $route->action }}</dd>
        </dl>
        <div class="sr-actions">
            <a class="sr-btn" href="{{ route('saved-routes.edit', $route) }}">Cancel</a>
            <button type="submit" class="sr-btn sr-btn-danger-solid">Delete route</button>
        </div>
    </form>
</div>
@endsection
