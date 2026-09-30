{{--
    A user's photo and details, for My profile ($mine) and Edit user: the photo with Upload new photo, then a box
    each for Personal info, Password and Roles, showing what's saved with an Edit button. Edit opens that box as a
    form (?edit=info), which saves only what's in it. Roles can only be changed on Edit user ($allRoles is the list
    to choose from there, and null on My profile).
--}}
@php
    $editing ??= null;
    $editUrl = fn (string $section) => $pageUrl.'?edit='.$section.'#profile-'.$section;
    $whose = $mine ? 'your' : "this user's";
@endphp
<div class="card profile">
    <section class="profile-photo" aria-label="Photo">
        @if ($photoUrl)
            <img class="profile-avatar" src="{{ $photoUrl }}" alt="Photo of {{ $user->Name }}" width="120" height="120" />
        @else
            <span class="profile-avatar profile-initials" aria-hidden="true">{{ $user->initials() }}</span>
        @endif
        <div>
            <div class="profile-photo-buttons">
                <form method="post" action="{{ $storePhotoUrl }}" enctype="multipart/form-data" class="photo-form" data-confirm="Are you sure you want to use this photo for {{ $mine ? 'your profile' : 'this user' }}?" data-confirm-kind="update">
                    @csrf
                    <label class="btn btn-outline photo-pick">
                        <span class="material-icon" aria-hidden="true">photo_camera</span>
                        <span>Upload new photo</span>
                        <input type="file" name="photo" accept="image/jpeg,image/png" required class="photo-input" aria-describedby="photo-hint" />
                    </label>
                    {{-- With JavaScript, choosing a photo uploads it. --}}
                    <button type="submit" class="btn btn-primary no-js-only">Upload</button>
                </form>
                @if ($photoUrl)
                    <form method="post" action="{{ $destroyPhotoUrl }}" data-confirm="Are you sure you want to remove {{ $whose }} photo?" data-confirm-kind="delete">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-ghost">Remove</button>
                    </form>
                @endif
            </div>
            <p class="muted profile-photo-hint" id="photo-hint">At least 800×800 px recommended.<br />JPG or PNG is allowed.</p>
            @error('photo')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>
    </section>

    <div class="profile-boxes">
        <section class="profile-box" aria-labelledby="profile-info">
            <div class="profile-box-head">
                <h2 id="profile-info">Personal info</h2>
                @if ($editing !== 'info')
                    <a class="btn btn-outline btn-sm" href="{{ $editUrl('info') }}"><span class="material-icon" aria-hidden="true">edit</span>Edit<span class="visually-hidden"> personal info</span></a>
                @endif
            </div>
            @if ($editing === 'info')
                <form method="post" action="{{ $updateUrl }}" data-confirm="Are you sure you want to update {{ $mine ? 'your profile' : 'this user' }}?" data-confirm-kind="update">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="section" value="info" />
                    <div class="profile-fields">
                        @include('setup::users._info-fields', ['autofocus' => true])
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a class="btn btn-ghost" href="{{ $pageUrl }}">Cancel</a>
                    </div>
                </form>
            @else
                <dl class="profile-facts">
                    <div><dt>Full name</dt><dd>{{ $user->Name }}</dd></div>
                    <div><dt>Username</dt><dd>{{ $user->Username }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $user->Email }}</dd></div>
                    <div><dt>Phone</dt><dd>@if (filled($user->Phone)){{ $user->Phone }}@else<span class="muted">Not given</span>@endif</dd></div>
                </dl>
            @endif
        </section>

        <section class="profile-box" aria-labelledby="profile-password">
            <div class="profile-box-head">
                <h2 id="profile-password">Password</h2>
                @if ($editing !== 'password')
                    <a class="btn btn-outline btn-sm" href="{{ $editUrl('password') }}"><span class="material-icon" aria-hidden="true">edit</span>Edit<span class="visually-hidden"> password</span></a>
                @endif
            </div>
            @if ($editing === 'password')
                <form method="post" action="{{ $updateUrl }}" class="profile-narrow" data-confirm="Are you sure you want to change {{ $whose }} password?" data-confirm-kind="update">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="section" value="password" />
                    @include('setup::users._password-field', ['inBox' => true])
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save password</button>
                        <a class="btn btn-ghost" href="{{ $pageUrl }}">Cancel</a>
                    </div>
                </form>
            @else
                <dl class="profile-facts">
                    <div>
                        <dt>Password</dt>
                        @if ($user->canLogIn())
                            <dd><span aria-hidden="true">••••••••</span><span class="visually-hidden">Set</span></dd>
                        @else
                            <dd><span class="muted">Not set. Without a password, this user can't log in.</span></dd>
                        @endif
                    </div>
                </dl>
            @endif
        </section>

        <section class="profile-box" aria-labelledby="profile-roles">
            <div class="profile-box-head">
                <h2 id="profile-roles">Roles</h2>
                @if ($allRoles !== null && $editing !== 'roles')
                    <a class="btn btn-outline btn-sm" href="{{ $editUrl('roles') }}"><span class="material-icon" aria-hidden="true">edit</span>Edit<span class="visually-hidden"> roles</span></a>
                @endif
            </div>
            @if ($allRoles !== null && $editing === 'roles')
                <form method="post" action="{{ $updateUrl }}" data-confirm="Are you sure you want to change {{ $whose }} roles?" data-confirm-kind="update">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="section" value="roles" />
                    @include('setup::users._roles-field', ['roles' => $allRoles, 'inBox' => true])
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save roles</button>
                        <a class="btn btn-ghost" href="{{ $pageUrl }}">Cancel</a>
                    </div>
                </form>
            @else
                @if ($user->roles->isEmpty())
                    <p class="profile-note muted">None.</p>
                @else
                    <div class="badges">
                        @foreach ($user->roles->sortBy('Name') as $role)
                            <span class="badge badge-role">{{ $role->Name }}</span>
                        @endforeach
                    </div>
                @endif
                @if ($allRoles === null)
                    <p class="field-hint">Only an administrator can change your roles.</p>
                @endif
            @endif
        </section>
    </div>
</div>
