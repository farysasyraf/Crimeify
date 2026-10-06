{{-- A plain page for the admin page to sit in. Set saved-routes.admin.layout to use your app's own layout instead. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; background: #f6f7f9; }
        @media (prefers-color-scheme: dark) { body { background: #111317; } }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
