@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')

@section('title', 'Error')

@section('content')
<h1>Something went wrong</h1>
<p class="muted">The request couldn't be completed. Try again, or go back to the <a href="{{ url('/') }}">start page</a>.</p>
@endsection
