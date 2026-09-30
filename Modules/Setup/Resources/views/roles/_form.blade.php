<div class="field">
    <label for="name">Name</label>
    @if ($role->exists && $role->isAdmin())
        <input type="text" id="name" name="name" value="{{ $role->Name }}" required maxlength="100" readonly aria-describedby="name-hint" @class(['is-invalid' => $errors->has('name')]) />
        <span class="field-hint" id="name-hint">The ADMIN role keeps its name: it's the role that opens the Routes page.</span>
    @else
        <input type="text" id="name" name="name" value="{{ old('name', $role->Name) }}" required maxlength="100" @class(['is-invalid' => $errors->has('name')]) />
    @endif
    @error('name')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="description">Description</label>
    <input type="text" id="description" name="description" value="{{ old('description', $role->Description) }}" maxlength="255" aria-describedby="description-hint" @class(['is-invalid' => $errors->has('description')]) />
    <span class="field-hint" id="description-hint">Optional. A short note on what this role is for.</span>
    @error('description')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>
