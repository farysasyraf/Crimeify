# Changelog

## 1.0.0 (unreleased)

First release, taken from the Routes page of Crimeify.

- Saved routes are added to the router after the app's routes, with fixed addresses before ones with parameters, and never replace an existing route or take its name.
- Controllers are limited to configured folders and a base class, and only public functions written there can be called.
- Each route is open to everyone who passes its middleware or only to chosen roles, through a role provider: spatie/laravel-permission, an Eloquent `Role` model, or your own class.
- An admin page behind the `manage-saved-routes` gate, which no one can open until the app defines it.
- An app can keep its own table by implementing `RouteRecord`.
- Optional caching, cleared with `php artisan saved-routes:clear`.
