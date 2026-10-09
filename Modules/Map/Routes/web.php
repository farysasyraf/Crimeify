<?php

use Illuminate\Support\Facades\Route;
use Modules\Map\Http\Controllers\MapController;

// The Map module's pages come from the Routes page (dbo.AppRoutes), as AMV's module pages come from its
// route rows in t3012menu: each one names this module's controller, like MapController or CrimeDataController, and
// its function. That includes the Crime data page (crime-data), its Excel download, and every one of its actions,
// like crime-data/update or crime-data/upload, so who can use each is set there.
// The module's RouteServiceProvider loads this file inside the "web" middleware group; any route that must
// always exist, whatever the Routes page says, can go here, behind Route::middleware('auth').

// The public map, for everyone without logging in, and the crime figures it shows. They're here rather than on the
// Routes page, which adds its routes behind the login, so they're always public. Each visitor can load them 120
// times a minute, far more than looking around needs. They come in Bahasa Melayu or English (SetPublicLocale).
Route::middleware(['throttle:120,1', 'public-locale'])->group(function () {
    Route::get('/public/map', [MapController::class, 'publicIndex'])->name('public.map');
    Route::get('/public/map/crime', [MapController::class, 'crime'])->name('public.map.crime');

    // A police district's own address, to share, like /public/map/johor/batu-pahat: the public map with its pin open,
    // and for the preview WhatsApp and the like show of the link, an image of its chart.
    Route::get('/public/map/{region}/{district}', [MapController::class, 'publicDistrict'])
        ->where(['region' => '[a-z0-9-]+', 'district' => '[a-z0-9-]+'])
        ->name('public.map.district');
    Route::get('/public/map/{region}/{district}/preview.png', [MapController::class, 'districtPreview'])
        ->where(['region' => '[a-z0-9-]+', 'district' => '[a-z0-9-]+'])
        ->name('public.map.district.preview');
});
