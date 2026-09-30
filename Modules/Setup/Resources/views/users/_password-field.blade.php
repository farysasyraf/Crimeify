{{-- The password: optional on Add user, and needed in the Password box, where it's what's being changed. --}}
@php($inBox = $inBox ?? false)
<div class="field">
    <label for="password">{{ $inBox ? 'New password' : 'Password' }}</label>
    <input type="password" id="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" aria-describedby="password-hint" @if ($inBox) required autofocus @endif @class(['is-invalid' => $errors->has('password')]) />
    <span class="field-hint" id="password-hint">
        @if ($inBox)
            At least 8 characters.
        @else
            Optional, at least 8 characters. Without a password, this user can't log in.
        @endif
    </span>
    @error('password')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>
