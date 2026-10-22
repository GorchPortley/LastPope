<?php

/*
|--------------------------------------------------------------------------
| Marketplace web routes (loaded in the "web" middleware group)
|--------------------------------------------------------------------------
|
| Checkout, seller onboarding with Stripe Connect, shipment actions and problem-report photos. All of them need a
| signed-in user. The Stripe webhooks are in routes/webhooks.php (no session, no CSRF).
|
| The GET /checkout page (route "checkout") and the cart (route "cart") are Folio pages in
| resources/themes/blank/pages.
|
*/

use App\Http\Controllers\Marketplace\CheckoutController;
use App\Http\Controllers\Marketplace\ConnectController;
use App\Http\Controllers\Marketplace\IssueAttachmentController;
use App\Http\Controllers\Marketplace\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Checkout
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.start');
    Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');
    Route::get('/checkout/cancel', [CheckoutController::class, 'cancel'])->name('checkout.cancel');

    // Stripe Connect onboarding for sellers
    Route::prefix('selling')->group(function () {
        Route::get('/connect', [ConnectController::class, 'start'])->name('selling.connect');
        Route::get('/connect/return', [ConnectController::class, 'returned'])->name('selling.connect.return');
        Route::get('/connect/refresh', [ConnectController::class, 'refresh'])->name('selling.connect.refresh');
        Route::get('/dashboard-link', [ConnectController::class, 'dashboardLink'])->name('selling.dashboard-link');

        Route::post('/orders/{sellerOrder}/ship', [ShipmentController::class, 'ship'])->name('selling.orders.ship');
    });

    // Buyer confirms a parcel arrived
    Route::post('/orders/{sellerOrder}/received', [ShipmentController::class, 'received'])->name('orders.received');

    // Photos of a problem report ("Report a problem"): private disk, buyer / seller / admins only.
    // The issue pages themselves are Folio pages (dashboard.issues, dashboard.issues.show).
    Route::get('/dashboard/issues/{orderIssue}/attachments/{message}/{index}', [IssueAttachmentController::class, 'show'])
        ->whereNumber(['orderIssue', 'message', 'index'])
        ->name('dashboard.issues.attachment');
});
