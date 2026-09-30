@extends('layouts.guest')

@section('title', 'Forgot password')

{{-- "Forgot password?" (PasswordResetController): the account's email, for a link to choose a new password. Once asked,
     it says a link is on its way if an account has the email, the same whether or not one does. --}}
@section('content')
@php($minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire'))
<form method="post" action="{{ route('password.email') }}" class="card form auth-card">
    @csrf
    <h1>Forgot password?</h1>
    <p class="muted">Type the email of your {{ config('app.name') }} account, and we'll email you a link to choose a new password.</p>

    @if (session('sent'))
        <div class="alert alert-info auth-sent" role="status">
            If an account has the email <strong>{{ session('sent') }}</strong>, a link to choose a new password is on its way to it.
            The link works for {{ $minutes }} minutes. If it hasn't arrived in a few minutes, look in the spam folder.
        </div>
    @endif

    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email', session('sent')) }}" required autofocus maxlength="255" autocomplete="email" @class(['is-invalid' => $errors->has('email')]) />
        @error('email')
            <span class="field-error" role="alert">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-block">{{ session('sent') ? 'Send another link' : 'Email me a link' }}</button>
    </div>
    <p class="auth-back"><a href="{{ route('login') }}">← Back to log in</a></p>
</form>
@endsection
