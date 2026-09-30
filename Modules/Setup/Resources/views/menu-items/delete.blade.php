@extends('layouts.app')

@section('title', 'Delete menu item')

@section('content')
<a class="back" href="{{ page_url('menu-items') }}">← Back to navigation menu</a>
<h1>Delete menu item</h1>

<form method="post" action="{{ page_url('menu-items/destroy', ['menu_item' => $item]) }}" class="card form" data-confirm="Are you sure you want to delete this menu item?" data-confirm-kind="delete">
    @csrf
    @method('DELETE')
    <p>This removes the link from the menu. The page it points to isn't affected.</p>
    @if ($item->children->isNotEmpty())
        <div class="alert alert-error" role="alert">
            The links under it will be deleted too:
            <ul class="sub-links">
                @foreach ($item->children as $child)
                    <li>
                        {{ $child->Label }}
                        @if ($child->children->isNotEmpty())
                            <ul>
                                @foreach ($child->children as $grandchild)
                                    <li>{{ $grandchild->Label }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($routeCount > 0)
        <p>
            {{ $routeCount }} {{ $routeCount === 1 ? 'route is' : 'routes are' }} grouped under this link{{ $item->children->isNotEmpty() ? ' or the links under it' : '' }}.
            {{ $routeCount === 1 ? 'It keeps' : 'They keep' }} working and will show under <strong>No menu</strong> on the
            <a href="{{ route('routes.index') }}">Routes</a> page.
        </p>
    @endif
    @if (! $item->isHeading() && url($item->Url) === page_url('menu-items'))
        <div class="alert alert-error" role="alert">
            This is the link to this Manage menu page. After deleting it, you can still get here by going to
            <code>/menu-items</code> in the address bar and adding the link back.
        </div>
    @endif
    <dl class="details">
        <dt>Label</dt>
        <dd>{{ $item->Label }}</dd>
        <dt>Link</dt>
        <dd>@include('setup::menu-items._link')</dd>
        <dt>Level</dt>
        <dd>{{ $item->level() }}</dd>
        <dt>Position</dt>
        <dd>{{ $item->SortOrder }}</dd>
        <dt>Visible to</dt>
        <dd>@include('setup::menu-items._visibility')</dd>
    </dl>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Delete menu item</button>
        <a class="btn btn-ghost" href="{{ page_url('menu-items') }}">Cancel</a>
    </div>
</form>
@endsection
