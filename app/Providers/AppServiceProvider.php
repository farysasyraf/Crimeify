<?php

namespace App\Providers;

use App\Models\MenuItem;
use App\Models\User;
use App\Support\Palette;
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
        // The command palette's sources, which the modules add to as they boot.
        $this->app->singleton(Palette::class);
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

        // The command palette's pages: the menu's links as the user's menu shows them, under the links above them, but
        // those whose page the user can't open, then their profile.
        $this->app->make(Palette::class)->add('Pages', 10, function (string $term, User $user) {
            $pages = [];
            $walk = function ($items, array $above) use (&$walk, &$pages, $user) {
                foreach ($items as $item) {
                    if (filled($item->Url) && Palette::canOpenUrl($user, url($item->Url))) {
                        $pages[] = ['label' => $item->Label, 'about' => $above === [] ? 'Menu' : implode(' › ', $above), 'url' => url($item->Url), 'icon' => $item->iconOrDefault()];
                    }
                    $walk($item->children, [...$above, $item->Label]);
                }
            };
            $walk(MenuItem::tree(MenuItem::ordered()->visibleTo($user)->get()), []);

            return [...$pages, ['label' => 'My profile', 'about' => 'Your details, photo and password', 'url' => route('profile'), 'icon' => 'account_circle']];
        });
    }
}
