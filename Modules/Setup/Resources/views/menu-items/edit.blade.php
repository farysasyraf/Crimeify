@extends('layouts.app')

@section('title', 'Edit menu item')

@section('content')
<a class="back" href="{{ page_url('menu-items') }}">← Back to navigation menu</a>
<h1>Edit menu item</h1>

<form method="post" action="{{ page_url('menu-items/update', ['menu_item' => $item]) }}" class="card form" data-confirm="Are you sure you want to update this menu item?" data-confirm-kind="update">
    @csrf
    @method('PUT')
    @include('setup::menu-items._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a class="btn btn-ghost" href="{{ page_url('menu-items') }}">Cancel</a>
    </div>
</form>
@endsection
