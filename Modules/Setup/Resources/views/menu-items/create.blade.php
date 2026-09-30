@extends('layouts.app')

@section('title', 'Add menu item')

@section('content')
<a class="back" href="{{ page_url('menu-items') }}">← Back to navigation menu</a>
<h1>Add menu item</h1>

<form method="post" action="{{ page_url('menu-items/store') }}" class="card form" data-confirm="Are you sure you want to save this menu item?" data-confirm-kind="save">
    @csrf
    @include('setup::menu-items._form')
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save menu item</button>
        <a class="btn btn-ghost" href="{{ page_url('menu-items') }}">Cancel</a>
    </div>
</form>
@endsection
