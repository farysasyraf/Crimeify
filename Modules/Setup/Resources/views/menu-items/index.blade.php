@extends('layouts.app')

@section('title', 'Navigation menu')

@section('content')
<div class="page-head">
    <div>
        <h1>Navigation menu</h1>
        <p class="muted">Links shown in the menu on the left, stored in dbo.MenuItems.</p>
    </div>
    <a class="btn btn-primary" href="{{ page_url('menu-items/create') }}">+ Add menu item</a>
</div>

@if ($items->isEmpty())
    <div class="empty">
        <p>The menu is empty.</p>
        <a class="btn btn-primary" href="{{ page_url('menu-items/create') }}">Add the first menu item</a>
    </div>
@else
    <div class="card table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th class="num">Position</th>
                    <th>Label</th>
                    <th class="num">Level</th>
                    <th>Link</th>
                    <th>Visible to</th>
                    <th class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @include('setup::menu-items._rows', ['items' => $items, 'level' => 1])
            </tbody>
        </table>
    </div>
@endif
@endsection
