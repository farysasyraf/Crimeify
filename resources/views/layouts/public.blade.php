<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title') · {{ config('app.name') }}</title>
    {{-- A page can give its own, like a police district's shared link. --}}
    <meta name="description" content="@yield('description', __("Crime in Malaysia by state and police district, from the Royal Malaysia Police's figures."))" />
    <link rel="icon" type="image/png" href="{{ versioned_asset('images/logo.png') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('vendor/select2/select2.min.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('css/site.css') }}" />
    {{-- A page's own stylesheets, like the Map page's, with @push('head'). --}}
    @stack('head')
    <script>document.documentElement.classList.add('js');</script>
    @include('layouts._translations')
</head>
{{-- The public pages, for everyone without logging in: the dashboard, the map and the game, with the logo and links
     to them along the top instead of the app's menu, so nothing of the app beyond them shows. They come in Bahasa
     Melayu or English, chosen with the switch at the top (SetPublicLocale). --}}
<body class="public-body">
    @php
        $pages = collect([
            ['route' => 'public.dashboard', 'label' => __('Dashboard'), 'icon' => 'dashboard'],
            ['route' => 'public.map', 'label' => __('Map'), 'icon' => 'map'],
            ['route' => 'public.game', 'label' => __('Game'), 'icon' => 'sports_esports'],
        ])->filter(fn (array $page) => Route::has($page['route']));
    @endphp
    <header class="public-topbar">
        <div class="public-bar">
            <a class="brand" href="{{ Route::has('public.dashboard') ? route('public.dashboard') : route('login') }}">
                <img src="{{ versioned_asset('images/logo.png') }}" alt="" width="30" height="30" />
                {{ config('app.name') }}
            </a>
            {{-- A page shown on a big screen, like a live game's for the room (section presenting), has only the logo. --}}
            @sectionMissing('presenting')
            <nav class="public-nav" aria-label="{{ __('Pages') }}">
                @foreach ($pages as $page)
                    {{-- The page, or one under it, like a live game's under the game's. --}}
                    @php $current = request()->routeIs($page['route'], $page['route'].'.*'); @endphp
                    <a href="{{ route($page['route']) }}" @class(['public-link', 'active' => $current]) aria-current="{{ $current ? 'page' : 'false' }}">
                        <span class="material-icon" aria-hidden="true">{{ $page['icon'] }}</span>{{ $page['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="public-account">
                @include('layouts._language-switch')
                @auth
                    <a class="btn btn-sm" href="{{ \App\Http\Controllers\LoginController::home() }}">{{ __('Open :app', ['app' => config('app.name')]) }}</a>
                @else
                    <a class="btn btn-sm btn-primary" href="{{ route('login') }}">{{ __('Log in') }}</a>
                @endauth
            </div>
            @endif
        </div>
    </header>

    {{-- main-class: main-full for a page that fills the window between the bar and the footer, like the map. --}}
    <main class="public-main @yield('main-class')">
        @yield('content')
    </main>

    <footer class="public-footer">
        <div class="public-bar">
            {{ config('app.name') }} · {{ __('Crime figures from the Royal Malaysia Police and the Department of Statistics Malaysia, through') }}
            <a href="{{ config('map.crime.about') }}">data.gov.my</a> (CC BY 4.0).
        </div>
    </footer>

    {{-- Lists as Select2 boxes, as in the app. --}}
    <script src="{{ versioned_asset('vendor/jquery/jquery.min.js') }}" defer></script>
    <script src="{{ versioned_asset('vendor/select2/select2.min.js') }}" defer></script>
    <script src="{{ versioned_asset('js/select2-init.js') }}" defer></script>
</body>
</html>
