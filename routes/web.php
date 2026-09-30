<?php

use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

// Logging in and out. Each module's pages come from its own Routes/web.php, like Modules/Setup/Routes/web.php,
// and the routes added on the Routes page are added by AppServiceProvider once every module's routes are in.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->post('/logout', [LoginController::class, 'logout'])->name('logout');
