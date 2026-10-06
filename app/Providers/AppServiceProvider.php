<?php

namespace App\Providers;

use App\Models\MenuItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // With an https APP_URL, every link and file address the app writes is https too, whatever the connection
        // between the host's load balancer and the app looks like.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            Facades\URL::forceScheme('https');
        }

        // Every page's layout draws the left menu from dbo.MenuItems, showing only the links the user's roles allow.
        Facades\View::composer('layouts.app', function (View $view) {
            $user = auth()->user();

            try {
                $visible = $user ? MenuItem::ordered()->visibleTo($user)->get() : collect();
                $navItems = MenuItem::tree($visible, dropEmptyHeadings: true);
            } catch (QueryException $e) {
                // Keep pages usable without a menu; the users list shows the database problem.
                report($e);
                $visible = $navItems = collect();
            }

            $view->with('navItems', $navItems);
            // Whether the page has a menu link of its own, which is then the only one marked as the page.
            $view->with('navPageHasLink', $visible->contains(fn (MenuItem $item) => filled($item->Url) && url($item->Url) === url()->current()));
        });
    }
}
