@extends('layouts.guest')

@section('title', 'Log in')

{{-- A night chase behind the login, silent and looping, darkened so the form reads over it. login-backdrop.js
     starts it, unless the device asks for less motion or to save data: then, as without JavaScript, the still frame
     (the poster) stays. Its button pauses it. It's only decoration, so screen readers skip it. --}}
@section('backdrop')
    <div class="login-backdrop" aria-hidden="true">
        <video class="login-backdrop-video" muted loop playsinline preload="none" tabindex="-1"
            poster="{{ versioned_asset('images/login-backdrop.jpg') }}" data-src="{{ versioned_asset('videos/login-backdrop.mp4') }}"></video>
    </div>
    <button type="button" class="login-backdrop-toggle" aria-label="Pause the background video" data-backdrop-toggle hidden>
        <span class="material-icon" aria-hidden="true">pause</span>
    </button>
    <script src="{{ versioned_asset('js/login-backdrop.js') }}" defer></script>
@endsection

@section('content')
<form method="post" action="{{ route('login') }}" class="card form auth-card">
    @csrf
    <h1>Log in</h1>
    <p class="muted">Use the email or username, and the password, for your {{ config('app.name') }} account.</p>

    <div class="field">
        <label for="login">Email or username</label>
        <input type="text" id="login" name="login" value="{{ old('login') }}" required autofocus maxlength="255" autocomplete="username" autocapitalize="none" spellcheck="false" @class(['is-invalid' => $errors->has('login')]) />
        @error('login')
            <span class="field-error" role="alert">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password" @class(['is-invalid' => $errors->has('password')]) />
        @error('password')
            <span class="field-error" role="alert">{{ $message }}</span>
        @enderror
        <a class="auth-forgot" href="{{ route('password.request') }}">Forgot password?</a>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </div>
</form>

@if (Route::has('public.dashboard') && Route::has('public.map'))
    <p class="auth-public">
        No account? See the <a href="{{ route('public.dashboard') }}">crime dashboard</a> and the
        <a href="{{ route('public.map') }}">map</a> without logging in.
    </p>
@endif
@endsection
