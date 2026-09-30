<div class="field">
    <label for="menu_item_id">Menu</label>
    <select id="menu_item_id" name="menu_item_id" required data-placeholder="Choose…" aria-describedby="menu-hint" @class(['is-invalid' => $errors->has('menu_item_id')])>
        <option value="">Choose…</option>
        @foreach ($menuGroups as $group)
            <optgroup label="{{ $group['label'] }}">
                @foreach ($group['options'] as $id => $label)
                    <option value="{{ $id }}" @selected((string) old('menu_item_id', $route->MenuItemId) === (string) $id)>{{ $label }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    <span class="field-hint" id="menu-hint">
        @if ($menuGroups === [])
            There are no level 2 or level 3 menu links yet. <a href="{{ page_url('menu-items/create') }}">Add one</a> first.
        @else
            A menu link with nothing under it.
        @endif
    </span>
    @error('menu_item_id')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="path">Route name</label>
    <input type="text" id="path" name="path" value="{{ old('path', $route->Path) }}" required maxlength="200" placeholder="reports/monthly" autocomplete="off" spellcheck="false" aria-describedby="path-hint" @class(['is-invalid' => $errors->has('path')]) />
    <span class="field-hint" id="path-hint">
        The address after the site name, like <code>reports/monthly</code>. It's also the route's name, so code can link to it
        with <code>route('reports/monthly')</code>.
    </span>
    @error('path')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="controller">Controller</label>
    <input type="text" id="controller" name="controller" value="{{ old('controller', $route->Controller) }}" required maxlength="150" placeholder="ReportController" list="controller-options" autocomplete="off" spellcheck="false" aria-describedby="controller-hint" @class(['is-invalid' => $errors->has('controller')]) />
    <datalist id="controller-options">
        @foreach (array_keys($controllers) as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
    <span class="field-hint" id="controller-hint">
        A class in <code>app/Http/Controllers</code> or a module's <code>Http/Controllers</code>, like
        <code>Setup\RoleController</code>. The class name alone is enough when only one module has it.
    </span>
    @error('controller')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="function">Function</label>
    <input type="text" id="function" name="function" value="{{ old('function', $route->Action) }}" required maxlength="100" placeholder="index" list="function-options" autocomplete="off" spellcheck="false" aria-describedby="function-hint" data-functions="{{ json_encode($controllers) }}" @class(['is-invalid' => $errors->has('function')]) />
    <datalist id="function-options"></datalist>
    <span class="field-hint" id="function-hint">A public function on that controller.</span>
    @error('function')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<fieldset class="field method-picker">
    <legend>Method</legend>
    <div class="method-options">
        @foreach (\App\Models\AppRoute::Methods as $method)
            <label class="check">
                <input type="radio" name="method" value="{{ $method }}" @checked(old('method', $route->HttpMethods) === $method) />
                <span>{{ $method }}</span>
            </label>
        @endforeach
    </div>
    <span class="field-hint">GET opens a page, POST adds from a form, PUT saves changes to a record and DELETE deletes one. ANY answers every method.</span>
    @error('method')
        <span class="field-error">{{ $message }}</span>
    @enderror
</fieldset>

<div class="field">
    <label for="parameter">Parameter <span class="muted">(optional)</span></label>
    <input type="text" id="parameter" name="parameter" value="{{ old('parameter', $route->Parameters) }}" maxlength="200" placeholder="id/kw" autocomplete="off" spellcheck="false" aria-describedby="parameter-hint" @class(['is-invalid' => $errors->has('parameter')]) />
    <span class="field-hint" id="parameter-hint">
        Names of the values at the end of the address, separated by <code>/</code>, like <code>id</code> or <code>id/kw</code>.
        Name each after the function's argument; end one with <code>?</code> to make it optional.
    </span>
    @error('parameter')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

{{-- As Manage menu's "Who can see this link", but checked when the page opens: anyone else gets "Not allowed". --}}
<fieldset class="field visibility">
    <legend>Who can open it</legend>
    <label class="check">
        <input type="radio" name="open_to" value="everyone" @checked($openTo === 'everyone') />
        <span>Everyone who is logged in</span>
    </label>
    <label class="check">
        <input type="radio" name="open_to" value="roles" @checked($openTo === 'roles') />
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
    <span class="field-hint">Hiding a menu link doesn't stop anyone opening its address; this does.</span>
    @error('open_to')
        <span class="field-error">{{ $message }}</span>
    @enderror
    @error('roles')
        <span class="field-error">{{ $message }}</span>
    @enderror
    @error('roles.*')
        <span class="field-error">{{ $message }}</span>
    @enderror
</fieldset>
