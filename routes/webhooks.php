<?php

/*
|--------------------------------------------------------------------------
| Stripe webhooks for the marketplace
|--------------------------------------------------------------------------
|
| Loaded WITHOUT the web middleware group: no session, no cookies, no CSRF. The signature of every
| request is verified with the endpoint's own signing secret (see StripeWebhookController).
| Wave's subscription webhook (POST /webhook/stripe) is separate and unchanged.
|
|   POST /stripe/marketplace/webhook   STRIPE_MARKETPLACE_WEBHOOK_SECRET   payments, refunds
|   POST /stripe/connect/webhook       STRIPE_CONNECT_WEBHOOK_SECRET       connected accounts
|
*/

use App\Http\Controllers\Stripe\ConnectWebhookController;
use App\Http\Controllers\Stripe\MarketplaceWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/stripe/marketplace/webhook', MarketplaceWebhookController::class)->name('stripe.marketplace.webhook');
Route::post('/stripe/connect/webhook', ConnectWebhookController::class)->name('stripe.connect.webhook');
