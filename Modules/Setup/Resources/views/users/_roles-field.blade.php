{{-- The role boxes: on Add user, and in Edit user's Roles box. Never on My profile: no one gives themselves more. --}}
{{-- After a failed save, show the boxes as they were ticked, even if none were. --}}
@php($selectedRoles = session()->hasOldInput() ? old('roles', []) : ($user->exists ? $user->roles->pluck('Id')->all() : []))
<fieldset class="field">
    <legend @class(['visually-hidden' => $inBox ?? false])>Roles</legend>
    @forelse ($roles as $role)
        <label class="check">
            <input type="checkbox" name="roles[]" value="{{ $role->Id }}" @checked(in_array($role->Id, $selectedRoles)) />
            <span>
                {{ $role->Name }}
                @if ($role->Description)
                    <span class="muted">· {{ $role->Description }}</span>
                @endif
            </span>
        </label>
    @empty
        <p class="field-hint">No roles yet. <a href="{{ page_url('roles/create') }}">Add a role</a> first, then come back to assign it.</p>
    @endforelse
    @error('roles.*')
        <span class="field-error">{{ $message }}</span>
    @enderror
    @error('roles')
        <span class="field-error">{{ $message }}</span>
    @enderror
</fieldset>
