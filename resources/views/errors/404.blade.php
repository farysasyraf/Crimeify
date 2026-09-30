@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')

@section('title', 'Not found')

@section('content')
<h1>Not found</h1>
<p class="muted">That page or user doesn't exist. It may have been deleted. Go back to the <a href="{{ url('/') }}">start page</a>.</p>
@endsection
