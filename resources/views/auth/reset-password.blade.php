@extends('layouts.guest')

@section('title', 'Choose a new password')

{{-- Where the emailed link leads (PasswordResetController): the new password, typed twice. A link that has run out,
     was used already or is cut short says so, with a way to ask for another. --}}
@section('content')
@php($minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire'))
@if ($valid)
    <form method="post" action="{{ route('password.update') }}" class="card form auth-card">
        @csrf
        <h1>Choose a new password</h1>
        <p class="muted">For the {{ config('app.name') }} account with the email {{ $email }}. It can't be the password the account has now.</p>
        <input type="hidden" name="token" value="{{ $token }}" />
        <input type="hidden" name="email" value="{{ $email }}" />
        {{-- The account, for password managers to save the new password under. --}}
        <input type="email" value="{{ $email }}" autocomplete="username" readonly hidden />

        <div class="field">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required autofocus minlength="8" maxlength="255" autocomplete="new-password" aria-describedby="password-hint" @class(['is-invalid' => $errors->has('password')]) />
            <span class="field-hint" id="password-hint">At least 8 characters.</span>
            @error('password')
                <span class="field-error" role="alert">{{ $message }}</span>
            @enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Type it again</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" maxlength="255" autocomplete="new-password" @class(['is-invalid' => $errors->has('password')]) />
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-block">Save new password</button>
        </div>
    </form>
@else
    <div class="card form auth-card">
        <h1>This link doesn't work</h1>
        <p class="muted">It has run out, was used already, or isn't all there. Each link works once, for {{ $minutes }} minutes.</p>
        <div class="form-actions">
            <a class="btn btn-primary btn-block" href="{{ route('password.request') }}">Ask for a new link</a>
        </div>
        <p class="auth-back"><a href="{{ route('login') }}">← Back to log in</a></p>
    </div>
@endif
@endsection
