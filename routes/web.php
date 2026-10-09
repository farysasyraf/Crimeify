<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Logging in and out. Each module's pages come from its own Routes/web.php, like Modules/Setup/Routes/web.php,
// and the routes added on the Routes page are added by AppServiceProvider once every module's routes are in.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    // "Forgot password?": a link by email, then a page to choose a new password with it (PasswordResetController).
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->post('/logout', [LoginController::class, 'logout'])->name('logout');

// The command palette's search, Ctrl+K on every page of the app. It asks again as each word is typed.
Route::middleware(['auth', 'throttle:240,1'])->get('/palette', PaletteController::class)->name('palette');
