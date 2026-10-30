<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;
use Wave\Facades\Wave;

// Wave routes
Wave::routes();

// Thumbnails: only reached when nginx has no file at /storage/thumbs/... yet; the controller makes it, and nginx
// serves it as a static file from then on (App\Support\Thumbnails).
Route::get('/storage/thumbs/{width}/{path}', \App\Http\Controllers\ThumbnailController::class)
    ->where(['width' => '[0-9]+', 'path' => '.+'])
    ->name('thumbnail');

// Snapshot downloads: files live on the private disk and are streamed after a policy check.
// Design files need a login (owner, admin, buyer or a free design); driver measurement snapshots are
// public for active drivers (the policy still limits inactive ones to their owner and admins).
Route::prefix('downloads')->group(function () {
    Route::get('/design-snapshots/{snapshot}', [DownloadController::class, 'designSnapshot'])
        ->middleware('auth')
        ->name('design-snapshots.download');

    Route::get('/driver-snapshots/{snapshot}', [DownloadController::class, 'driverSnapshot'])
        ->name('driver-snapshots.download');
});

// "This link is for another account" (EnsureLinkRecipient): sign out and come back to the link after signing in again.
Route::post('/link/switch-account', \App\Http\Controllers\SwitchAccountController::class)
    ->middleware('auth')
    ->name('link.switch-account');
