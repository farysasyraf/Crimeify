@php
    // After a failed save, show the choices as they were made, even if no role was ticked.
    $level = (int) old('level', $item->level());
    $parentForLevel2 = old('parent_for_level_2', $level === 2 ? $item->ParentId : null);
    $parentForLevel3 = old('parent_for_level_3', $level === 3 ? $item->ParentId : null);
    $visibleTo = old('visible_to', $item->VisibleToEveryone === false ? 'roles' : 'everyone');
    $selectedRoles = session()->hasOldInput() ? old('roles', []) : ($item->exists ? $item->roles->pluck('Id')->all() : []);
    // The Link list holds a page, "other" for an address typed in the box, or blank for a heading.
    // A saved address that isn't in the list, like an outside site, shows as Other with the address filled in.
    $link = (string) old('url', $item->Url);
    $otherAddress = old('url_other', '');
    if ($link !== '' && $link !== 'other' && ! in_array($link, [...$pages['builtIn'], ...$pages['saved']])) {
        [$link, $otherAddress] = ['other', $link];
    }
@endphp

<div class="field">
    <label for="label">Label</label>
    <input type="text" id="label" name="label" value="{{ old('label', $item->Label) }}" required maxlength="100" @class(['is-invalid' => $errors->has('label')]) />
    @error('label')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field link-picker">
    <label for="url">Link</label>
    <select id="url" name="url" aria-describedby="url-hint" @class(['is-invalid' => $errors->has('url')])>
        <option value="" @selected($link === '')>None · a heading that groups the links under it</option>
        @if ($pages['builtIn'] !== [])
            <optgroup label="Pages in this app">
                @foreach ($pages['builtIn'] as $address)
                    <option value="{{ $address }}" @selected($link === $address)>{{ $address }}</option>
                @endforeach
            </optgroup>
        @endif
        @if ($pages['saved'] !== [])
            <optgroup label="Routes from Manage routes">
                @foreach ($pages['saved'] as $address)
                    <option value="{{ $address }}" @selected($link === $address)>{{ $address }}</option>
                @endforeach
            </optgroup>
        @endif
        <option value="other" @selected($link === 'other')>Other address…</option>
    </select>
    <span class="field-hint" id="url-hint">
        The pages listed open without anything added to the address. For anything else, like an outside site,
        choose <strong>Other address…</strong>
    </span>
    @error('url')
        <span class="field-error">{{ $message }}</span>
    @enderror

    <div class="link-other">
        <label for="url_other">Address</label>
        <input type="text" id="url_other" name="url_other" value="{{ $otherAddress }}" maxlength="255" placeholder="https://example.com" aria-describedby="url-other-hint" @class(['is-invalid' => $errors->has('url_other')]) />
        <span class="field-hint" id="url-other-hint">
            A full address starting with https://, or a path in this app starting with /, like <code>/users?search=ali</code>.
        </span>
        @error('url_other')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>
</div>

<fieldset class="field level-picker">
    <legend>Level</legend>
    @if ($maxLevel < 3)
        <p class="field-hint">This link has sub-links under it, so it can be at most level {{ $maxLevel }}.</p>
    @endif

    <label class="check">
        <input type="radio" name="level" value="1" @checked($level === 1) />
        <span>Level 1 <span class="muted">· top of the menu</span></span>
    </label>

    <label class="check">
        <input type="radio" name="level" value="2" @checked($level === 2) @disabled($maxLevel < 2) />
        <span>Level 2 <span class="muted">· under a level 1 link</span></span>
    </label>
    <div class="check-group parent-for-2">
        <label for="parent_for_level_2">Put it under</label>
        <select id="parent_for_level_2" name="parent_for_level_2" data-placeholder="Choose a level 1 link…" @class(['is-invalid' => $errors->has('parent_for_level_2')])>
            <option value="">Choose a level 1 link…</option>
            @foreach ($level1Items as $option)
                <option value="{{ $option->Id }}" @selected((string) $parentForLevel2 === (string) $option->Id)>{{ $option->Label }}</option>
            @endforeach
        </select>
        @error('parent_for_level_2')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    <label class="check">
        <input type="radio" name="level" value="3" @checked($level === 3) @disabled($maxLevel < 3) />
        <span>Level 3 <span class="muted">· under a level 2 link</span></span>
    </label>
    <div class="check-group parent-for-3">
        <label for="parent_for_level_3">Put it under</label>
        <select id="parent_for_level_3" name="parent_for_level_3" data-placeholder="Choose a level 2 link…" @class(['is-invalid' => $errors->has('parent_for_level_3')])>
            <option value="">Choose a level 2 link…</option>
            @foreach ($level2Items as $option)
                <option value="{{ $option->Id }}" @selected((string) $parentForLevel3 === (string) $option->Id)>{{ $option->parent->Label }} › {{ $option->Label }}</option>
            @endforeach
        </select>
        @if ($level2Items->isEmpty())
            <span class="field-hint">There are no level 2 links yet.</span>
        @endif
        @error('parent_for_level_3')
            <span class="field-error">{{ $message }}</span>
        @enderror
    </div>

    @error('level')
        <span class="field-error">{{ $message }}</span>
    @enderror
</fieldset>

{{-- What the sidebar shows in front of the link at any level, as AMV's Icon Menu. --}}
<div class="field icon-field">
    <label for="icon">Icon</label>
    <div class="icon-input">
        <span class="material-icon icon-preview" aria-hidden="true" data-icon-preview>{{ old('icon', $item->Icon) ?: \App\Models\MenuItem::DefaultIcon }}</span>
        <input type="text" id="icon" name="icon" value="{{ old('icon', $item->Icon) }}" maxlength="50" placeholder="{{ \App\Models\MenuItem::DefaultIcon }}" autocomplete="off" spellcheck="false" aria-describedby="icon-hint" @class(['is-invalid' => $errors->has('icon')]) />
    </div>
    <span class="field-hint" id="icon-hint">
        The name of a <a href="https://fonts.google.com/icons?icon.style=Rounded" target="_blank" rel="noopener">Material icon</a>,
        like <code>account_balance</code>, shown in front of the link in the menu, and on its own when the menu is narrowed.
        Leave blank for <code>{{ \App\Models\MenuItem::DefaultIcon }}</code>.
    </span>
    @error('icon')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="sort_order">Position</label>
    <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $item->SortOrder) }}" required min="0" max="9999" step="1" aria-describedby="sort-order-hint" @class(['is-invalid' => $errors->has('sort_order')]) />
    <span class="field-hint" id="sort-order-hint">Lower numbers appear higher, among the links at the same place in the menu.</span>
    @error('sort_order')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<fieldset class="field visibility">
    <legend>Who can see this link</legend>
    <label class="check">
        <input type="radio" name="visible_to" value="everyone" @checked($visibleTo === 'everyone') />
        <span>Everyone who is logged in</span>
    </label>
    <label class="check">
        <input type="radio" name="visible_to" value="roles" @checked($visibleTo === 'roles') />
        <span>Only users with these roles:</span>
    </label>
    <div class="check-group">
        @forelse ($roles as $role)
            <label class="check">
                <input type="checkbox" name="roles[]" value="{{ $role->Id }}" @checked(in_array($role->Id, $selectedRoles)) />
                <span>{{ $role->Name }}</span>
            </label>
        @empty
            <p class="field-hint">No roles yet. <a href="{{ page_url('roles/create') }}">Add a role</a> first.</p>
        @endforelse
    </div>
    <span class="field-hint">A sub-link only shows to someone who can also see the link it's under.</span>
    @error('visible_to')
        <span class="field-error">{{ $message }}</span>
    @enderror
    @error('roles')
        <span class="field-error">{{ $message }}</span>
    @enderror
    @error('roles.*')
        <span class="field-error">{{ $message }}</span>
    @enderror
</fieldset>

<script src="{{ versioned_asset('js/menu-form.js') }}" defer></script>
