@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')

@section('title', 'Not allowed')

@section('content')
<h1>Not allowed</h1>
<p class="muted">{{ $exception->getMessage() ?: "You don't have access to this page." }} Ask an administrator if you need it.</p>
@endsection
