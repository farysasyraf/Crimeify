<?php

use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;
use Modules\Setup\Http\Controllers\AppRouteController;
use Modules\Setup\Http\Controllers\UserController;
use Modules\Setup\Http\Controllers\UserPhotoController;

// The Setup module's pages that aren't on the Routes page. Its users, roles and menu pages, and every one of their
// actions (users/edit, users/update, roles/destroy…), are routes saved on the Routes page, so who can use each is set
// there. What stays here is what everyone logged in must always have, and the Routes page itself, so it can't be
// deleted or broken from itself. The module's RouteServiceProvider loads this file inside the "web" middleware group;
// every page here needs a login.
Route::middleware('auth')->group(function () {
    // The site's address: the page to start from. Someone not logged in gets the public dashboard (bootstrap/app.php).
    Route::get('/', fn () => redirect(LoginController::home()))->name('home');

    // My profile: everyone's own photo, name, username, email, phone and password, whoever can manage users.
    Route::get('/profile', [UserController::class, 'profile'])->name('profile');
    Route::put('/profile', [UserController::class, 'updateProfile'])->name('profile.update');
    Route::get('/profile/photo', [UserPhotoController::class, 'showMine'])->name('profile.photo');
    Route::post('/profile/photo', [UserPhotoController::class, 'storeMine'])->name('profile.photo.store');
    Route::delete('/profile/photo', [UserPhotoController::class, 'destroyMine'])->name('profile.photo.destroy');

    // Whether a username is free, as it's typed on My profile, Add user and Edit user. A POST, with the page's CSRF
    // token, so it isn't offered as a page for the menu to link to.
    Route::middleware('throttle:60,1')->post('/username-check', [UserController::class, 'checkUsername'])->name('username.check');

    // The Routes page, only for ADMIN: it decides who can open every other page, so who can open it is set here rather
    // than on itself, where anyone let in could let themselves into everything.
    Route::middleware('admin')->group(function () {
        Route::get('/routes/{app_route}/delete', [AppRouteController::class, 'delete'])
            ->whereNumber('app_route')
            ->name('routes.delete');

        // The Routes page shows the add form itself, so there's no separate create page.
        Route::resource('routes', AppRouteController::class)
            ->except(['create', 'show'])
            ->parameters(['routes' => 'app_route'])
            ->where(['app_route' => '[0-9]+']);
    });
});
