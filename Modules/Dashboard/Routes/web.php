<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

// The dashboard in the app (dashboard, DashboardController@index) is a route saved on the Routes page, so it's
// managed there with the rest. The module's RouteServiceProvider loads this file inside the "web" middleware group.

// The public dashboard, for everyone without logging in, and /public, which starts there. They're here rather than on
// the Routes page, which adds its routes behind the login, so they're always public. Each visitor can load it 120
// times a minute, far more than looking around needs. It comes in Bahasa Melayu or English (SetPublicLocale).
Route::middleware(['throttle:120,1', 'public-locale'])->get('/public/dashboard', [DashboardController::class, 'publicIndex'])->name('public.dashboard');
Route::redirect('/public', '/public/dashboard');
