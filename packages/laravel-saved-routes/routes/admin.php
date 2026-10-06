<?php

use Farysasyraf\SavedRoutes\Http\Controllers\SavedRouteController;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Support\Facades\Route;

// The admin page, in the code rather than saved on itself, so it can't be deleted or locked from itself.
Route::middleware(config('saved-routes.admin.middleware', ['web', 'auth', 'can:'.SavedRoutes::GATE]))
    ->prefix(config('saved-routes.admin.path', 'saved-routes'))
    ->name('saved-routes.')
    ->group(function () {
        // The page shows the add form itself, so there's no separate create page.
        Route::get('/', [SavedRouteController::class, 'index'])->name('index');
        Route::post('/', [SavedRouteController::class, 'store'])->name('store');
        Route::get('/{savedRoute}/edit', [SavedRouteController::class, 'edit'])->whereNumber('savedRoute')->name('edit');
        Route::put('/{savedRoute}', [SavedRouteController::class, 'update'])->whereNumber('savedRoute')->name('update');
        Route::get('/{savedRoute}/delete', [SavedRouteController::class, 'delete'])->whereNumber('savedRoute')->name('delete');
        Route::delete('/{savedRoute}', [SavedRouteController::class, 'destroy'])->whereNumber('savedRoute')->name('destroy');
    });
