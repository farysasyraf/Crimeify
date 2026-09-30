{{-- Name, username, email and phone: on Add user, and in the Personal info box of My profile and Edit user. --}}
<div class="field">
    <label for="name">Full name</label>
    <input type="text" id="name" name="name" value="{{ old('name', $user->Name) }}" required maxlength="100" autocomplete="name" @if ($autofocus ?? false) autofocus @endif @class(['is-invalid' => $errors->has('name')]) />
    @error('name')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

{{-- As it's typed, username-check.js asks whether the username is free, and says so under it. Not filled in by the
     browser: on Add user and Edit user, that would be the username of whoever is logged in. --}}
@pushOnce('head')
    <script src="{{ versioned_asset('js/username-check.js') }}" defer></script>
@endPushOnce
<div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="{{ old('username', $user->Username) }}" required minlength="3" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false"
        data-username-check="{{ route('username.check') }}" data-current="{{ $user->Username }}" aria-describedby="username-hint username-status" @class(['is-invalid' => $errors->has('username')]) />
    <span class="field-hint" id="username-hint">3 to 30 letters, numbers, dots, dashes or underscores. Logs in like the email; capitals don't matter.</span>
    <span class="username-status" id="username-status" aria-live="polite" data-username-status hidden></span>
    @error('username')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="{{ old('email', $user->Email) }}" required maxlength="255" autocomplete="email" @class(['is-invalid' => $errors->has('email')]) />
    @error('email')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="phone">Phone <span class="muted">(optional)</span></label>
    <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->Phone) }}" maxlength="30" autocomplete="tel" placeholder="012-345 6789" @class(['is-invalid' => $errors->has('phone')]) />
    @error('phone')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>
