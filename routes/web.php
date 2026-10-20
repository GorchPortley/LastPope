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

// Snapshot downloads: files live on the private disk and are streamed after a policy check.
Route::middleware('auth')->prefix('downloads')->group(function () {
    Route::get('/design-snapshots/{snapshot}', [DownloadController::class, 'designSnapshot'])
        ->name('design-snapshots.download');

    Route::get('/driver-snapshots/{snapshot}', [DownloadController::class, 'driverSnapshot'])
        ->name('driver-snapshots.download');
});
