@extends(config('saved-routes.admin.layout', 'saved-routes::layout'))

@section('title', 'Saved routes')

@section(config('saved-routes.admin.section', 'content'))
@include('saved-routes::_styles')
<div class="saved-routes">
    <header class="sr-head">
        <h1>Saved routes</h1>
        <p class="sr-muted">
            {{ $totalCount }} {{ $totalCount === 1 ? 'route' : 'routes' }}, added to the app after the routes in its code, which they never replace.
            Each one goes through the {{ implode(', ', (array) config('saved-routes.middleware')) }} middleware.
        </p>
    </header>

    @if (session('saved-routes.message'))
        <div class="sr-alert sr-alert-ok" role="status">{{ session('saved-routes.message') }}</div>
    @endif

    @if ($routesCached)
        <div class="sr-alert sr-alert-error" role="alert">
            Laravel's routes are cached, so changes here won't take effect until the cache is made again with
            <code>php artisan route:cache</code>, or cleared with <code>php artisan route:clear</code>.
        </div>
    @endif

    <div class="sr-layout">
        <form method="post" action="{{ $route->exists ? route('saved-routes.update', $route) : route('saved-routes.store') }}" class="sr-card">
            @csrf
            @if ($route->exists)
                @method('PUT')
            @endif
            <h2>{{ $route->exists ? 'Edit route' : 'Add route' }}</h2>

            <div class="sr-field">
                <label for="sr-path">Route name</label>
                <input type="text" id="sr-path" name="path" value="{{ old('path', $route->path) }}" required maxlength="200" placeholder="reports/monthly" autocomplete="off" spellcheck="false" aria-describedby="sr-path-hint" @class(['is-invalid' => $errors->has('path')]) />
                <span class="sr-hint" id="sr-path-hint">
                    The address after the site name, like <code>reports/monthly</code>. It's also the route's name, so code can link to it
                    with <code>route('reports/monthly')</code>.
                </span>
                @error('path')
                    <span class="sr-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="sr-field">
                <label for="sr-controller">Controller</label>
                <input type="text" id="sr-controller" name="controller" value="{{ old('controller', $route->controller) }}" required maxlength="150" placeholder="ReportController" list="sr-controller-options" autocomplete="off" spellcheck="false" aria-describedby="sr-controller-hint" @class(['is-invalid' => $errors->has('controller')]) />
                <datalist id="sr-controller-options">
                    @foreach (array_keys($controllers) as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
                <span class="sr-hint" id="sr-controller-hint">A class in one of the app's controller folders. The class name alone is enough when only one folder has it.</span>
                @error('controller')
                    <span class="sr-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="sr-field">
                <label for="sr-function">Function</label>
                <input type="text" id="sr-function" name="function" value="{{ old('function', $route->action) }}" required maxlength="100" placeholder="index" list="sr-function-options" autocomplete="off" spellcheck="false" aria-describedby="sr-function-hint" data-functions="{{ json_encode($controllers) }}" @class(['is-invalid' => $errors->has('function')]) />
                <datalist id="sr-function-options"></datalist>
                <span class="sr-hint" id="sr-function-hint">A public function on that controller.</span>
                @error('function')
                    <span class="sr-error">{{ $message }}</span>
                @enderror
            </div>

            <fieldset class="sr-field">
                <legend>Method</legend>
                <div class="sr-choices">
                    @foreach (\Farysasyraf\SavedRoutes\SavedRoutes::METHODS as $method)
                        <label class="sr-check">
                            <input type="radio" name="method" value="{{ $method }}" @checked(old('method', $route->method) === $method) />
                            <span>{{ $method }}</span>
                        </label>
                    @endforeach
                </div>
                <span class="sr-hint">GET opens a page, POST adds from a form, PUT and PATCH save changes to a record and DELETE deletes one. ANY answers every method.</span>
                @error('method')
                    <span class="sr-error">{{ $message }}</span>
                @enderror
            </fieldset>

            <div class="sr-field">
                <label for="sr-parameter">Parameter <span class="sr-muted">(optional)</span></label>
                <input type="text" id="sr-parameter" name="parameter" value="{{ old('parameter', $route->parameters) }}" maxlength="200" placeholder="id/kw" autocomplete="off" spellcheck="false" aria-describedby="sr-parameter-hint" @class(['is-invalid' => $errors->has('parameter')]) />
                <span class="sr-hint" id="sr-parameter-hint">
                    Names of the values at the end of the address, separated by <code>/</code>, like <code>id</code> or <code>id/kw</code>.
                    Name each after the function's argument; end one with <code>?</code> to make it optional.
                </span>
                @error('parameter')
                    <span class="sr-error">{{ $message }}</span>
                @enderror
            </div>

            @if ($rolesEnabled)
                <fieldset class="sr-field">
                    <legend>Who can open it</legend>
                    <label class="sr-check">
                        <input type="radio" name="open_to" value="everyone" @checked($openTo === 'everyone') />
                        <span>Everyone who is logged in</span>
                    </label>
                    <label class="sr-check">
                        <input type="radio" name="open_to" value="roles" @checked($openTo === 'roles') />
                        <span>Only users with these roles:</span>
                    </label>
                    <div class="sr-roles">
                        @forelse ($roleNames as $key => $name)
                            <label class="sr-check">
                                <input type="checkbox" name="roles[]" value="{{ $key }}" @checked(in_array((string) $key, $selectedRoles, true)) />
                                <span>{{ $name }}</span>
                            </label>
                        @empty
                            <span class="sr-hint">There are no roles yet.</span>
                        @endforelse
                    </div>
                    <span class="sr-hint">Checked each time the address is opened, so hiding a link to it isn't needed to keep others out.</span>
                    @error('open_to')
                        <span class="sr-error">{{ $message }}</span>
                    @enderror
                    @error('roles')
                        <span class="sr-error">{{ $message }}</span>
                    @enderror
                    @error('roles.*')
                        <span class="sr-error">{{ $message }}</span>
                    @enderror
                </fieldset>
            @endif

            <div class="sr-actions">
                @if ($route->exists)
                    <a class="sr-btn sr-btn-danger" href="{{ route('saved-routes.delete', $route) }}">Delete</a>
                    <a class="sr-btn" href="{{ route('saved-routes.index') }}">Cancel</a>
                    <button type="submit" class="sr-btn sr-btn-primary">Update</button>
                @else
                    <button type="submit" class="sr-btn sr-btn-primary">Save</button>
                @endif
            </div>
        </form>

        <section class="sr-card" aria-labelledby="sr-list-heading">
            <h2 id="sr-list-heading">Routes</h2>
            <div class="sr-toolbar">
                <form method="get" action="{{ url()->current() }}" class="sr-search" role="search">
                    <input type="search" name="search" value="{{ $search }}" placeholder="Search route, controller or function" aria-label="Search routes" />
                    <button type="submit" class="sr-btn">Search</button>
                    @if ($search !== '')
                        <a class="sr-btn" href="{{ url()->current() }}">Clear</a>
                    @endif
                </form>
                @if ($route->exists)
                    <a class="sr-btn sr-btn-primary" href="{{ route('saved-routes.index') }}">+ Add route</a>
                @endif
            </div>

            @if ($routes->isEmpty())
                <p class="sr-empty">
                    {{ $search !== '' ? "Nothing matches “{$search}”." : 'No routes yet. Fill in the form to add the first one.' }}
                </p>
            @else
                <ul class="sr-list">
                    @foreach ($routes as $saved)
                        <li @class(['sr-row', 'is-current' => $route->exists && $saved->is($route)])>
                            <a class="sr-row-main" href="{{ route('saved-routes.edit', [$saved, 'search' => $search ?: null]) }}" @if ($route->exists && $saved->is($route)) aria-current="true" @endif>
                                <span class="sr-method">{{ $saved->method }}</span>
                                <code class="sr-address">{{ $highlight($saved->address()) }}</code>
                                <span class="sr-handler">{{ $highlight($saved->handler()) }}</span>
                                @if ($saved->isBroken())
                                    <span class="sr-badge sr-badge-danger">Code not found</span>
                                @endif
                                @if ($saved->isLimitedToRoles())
                                    <span class="sr-badge" title="Only users with these roles can open it">
                                        <svg width="12" height="12" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5 7V5a3 3 0 0 1 6 0v2h1a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1Zm1.5 0h3V5a1.5 1.5 0 0 0-3 0v2Z"/></svg>
                                        {{ implode(', ', $saved->savedRouteRoles()) ?: 'No one' }}
                                    </span>
                                @endif
                            </a>
                            @if ($saved->canOpen())
                                {{-- Any parameters are optional here, so the bare path opens it. --}}
                                <a class="sr-btn sr-btn-sm" href="{{ url($saved->path) }}" target="_blank" rel="noopener">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <script>
        // Suggest the chosen controller's public functions in the Function box. Without JavaScript the box still
        // works; it just offers no suggestions.
        (() => {
            const controller = document.getElementById('sr-controller');
            const functionBox = document.getElementById('sr-function');
            const suggestions = document.getElementById('sr-function-options');
            let functions = {};
            try {
                functions = JSON.parse(functionBox.dataset.functions) || {};
            } catch {
                // Leave the box without suggestions.
            }

            const suggest = () => {
                const typed = controller.value.trim().replace(/\//g, '\\').replace(/^\\+/, '').toLowerCase();
                const names = Object.keys(functions);
                // The name as listed, like Admin\ReportController, or just the class when only one folder has it.
                const inFolder = names.filter((key) => key.toLowerCase().endsWith('\\' + typed));
                const name = names.find((key) => key.toLowerCase() === typed) ?? (inFolder.length === 1 ? inFolder[0] : undefined);
                suggestions.replaceChildren(...(functions[name] || []).map((fn) => new Option(fn)));
            };

            controller.addEventListener('input', suggest);
            suggest();
        })();
    </script>
</div>
@endsection
