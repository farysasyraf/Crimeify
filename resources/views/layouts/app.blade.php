<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ versioned_asset('images/logo.png') }}" />
    {{-- Select2's own styles first, so site.css can give its lists the app's look. --}}
    <link rel="stylesheet" href="{{ versioned_asset('vendor/select2/select2.min.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('css/site.css') }}" />
    {{-- A page's own stylesheets, like the Map page's, with @push('head'). --}}
    @stack('head')
    {{-- Lets the stylesheet turn the menu into a drawer on smaller screens only when the script that opens it will run,
         and narrows the menu before the page is drawn if this browser left it narrow. --}}
    <script>
        document.documentElement.classList.add('js');
        try {
            if (localStorage.getItem('myapp.sidebar.narrow') === '1') {
                document.documentElement.classList.add('menu-narrow');
            }
        } catch {
            // Private windows or blocked storage: the menu starts wide.
        }
    </script>
</head>
<body>
    <div class="app-shell">
        {{-- Laid out like AMV's sidebar: logo, the signed-in user, then the modules from dbo.MenuItems. --}}
        <aside class="sidebar" id="sidebar">
            <div class="side-header">
                <a class="side-brand" href="{{ \App\Http\Controllers\LoginController::home() }}">
                    <img src="{{ versioned_asset('images/logo.png') }}" alt="" width="32" height="32" />
                    <span class="side-text">{{ config('app.name') }}</span>
                </a>
                <button type="button" class="side-close" aria-label="Close menu">
                    <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
                </button>
            </div>
            <hr class="side-divider" />
            @auth
                @include('layouts._profile')
                <hr class="side-divider" />
            @endauth
            <nav class="side-nav" aria-label="Main menu">
                @if ($navItems->isEmpty())
                    <p class="side-empty">No menu items yet.</p>
                @else
                    <h2 class="side-section">Modules</h2>
                    @include('layouts._nav-items', ['items' => $navItems, 'level' => 1])
                @endif
            </nav>
            {{-- Runs here, before the page is drawn, so closed groups never flash open. --}}
            <script src="{{ versioned_asset('js/sidebar.js') }}"></script>
        </aside>
        <div class="menu-backdrop" aria-hidden="true"></div>

        <div class="app-main">
            <header class="topbar app-topbar">
                {{-- The same ≡ as AMV's toggles: below 1200px wide it slides the menu in; above, it narrows the menu to its icons. --}}
                <button type="button" class="btn btn-sm btn-ghost menu-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
                    <svg width="20" height="20" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2.5 4h11M2.5 8h11M2.5 12h11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="btn btn-sm btn-ghost menu-narrow-toggle" aria-controls="sidebar" aria-pressed="false" aria-label="Show only icons in the menu">
                    <svg width="20" height="20" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2.5 4h11M2.5 8h11M2.5 12h11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
                @auth
                    <div class="topbar-user">
                        <span class="topbar-name">{{ auth()->user()->Name }}</span>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm">Log out</button>
                        </form>
                    </div>
                @endauth
            </header>

            <main class="main">
                @include('layouts._flash')
                @yield('content')
            </main>

            <footer class="footer">
                Connected to <strong>MyAppDB</strong> on SQL Server (localhost)
            </footer>
        </div>
    </div>
    {{-- The "Please confirm" box shown before a form adds, changes or deletes something, as AMV's Swal.fire "Kepastian!". --}}
    <script src="{{ versioned_asset('js/vendor/sweetalert2.all.min.js') }}" defer></script>
    <script src="{{ versioned_asset('js/confirm.js') }}" defer></script>
    {{-- Notifications as toasts at the top right, as AMV's toast(). --}}
    <script src="{{ versioned_asset('js/toast.js') }}" defer></script>
    {{-- Every list as a Select2 box with a search, as in AMV. Select2 needs jQuery. --}}
    <script src="{{ versioned_asset('vendor/jquery/jquery.min.js') }}" defer></script>
    <script src="{{ versioned_asset('vendor/select2/select2.min.js') }}" defer></script>
    <script src="{{ versioned_asset('js/select2-init.js') }}" defer></script>
    {{-- Lists that refresh in place when a filter or page is chosen, rather than the whole page. --}}
    <script src="{{ versioned_asset('js/live-table.js') }}" defer></script>
</body>
</html>
