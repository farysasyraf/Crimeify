<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ versioned_asset('images/logo.png') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('css/site.css') }}" />
    <script>
        document.documentElement.classList.add('js');
    </script>
</head>
<body @class(['has-backdrop' => View::hasSection('backdrop')])>
    {{-- Behind everything, like the login page's video. --}}
    @yield('backdrop')

    <header class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="{{ Route::has('public.dashboard') ? route('public.dashboard') : route('login') }}">
                <img src="{{ versioned_asset('images/logo.png') }}" alt="" width="30" height="30" />
                {{ config('app.name') }}
            </a>
        </div>
    </header>

    <main class="container guest-main">
        @include('layouts._flash')
        @yield('content')
    </main>
    {{-- Notifications as toasts at the top right, as AMV's toast(), like "You've logged out." --}}
    <script src="{{ versioned_asset('js/vendor/sweetalert2.all.min.js') }}" defer></script>
    <script src="{{ versioned_asset('js/toast.js') }}" defer></script>
</body>
</html>
