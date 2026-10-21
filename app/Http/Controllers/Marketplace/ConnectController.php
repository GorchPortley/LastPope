<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\ConnectException;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\ConnectOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Seller onboarding with Stripe Connect Express (all routes need a signed-in user):
 *
 *   GET /selling/connect          selling.connect         start or continue onboarding -> Stripe
 *   GET /selling/connect/return   selling.connect.return  Stripe sends the seller back here
 *   GET /selling/connect/refresh  selling.connect.refresh the onboarding link expired: new link -> Stripe
 *   GET /selling/dashboard-link   selling.dashboard-link  one-time login to the Express dashboard -> Stripe
 *
 * After Stripe, sellers land on route "selling.dashboard" if the app defines it, else on "dashboard",
 * with a flash `success` / `info` / `error` message.
 */
class ConnectController extends Controller
{
    public function start(Request $request, ConnectOnboardingService $connect): RedirectResponse
    {
        try {
            return redirect()->away($connect->onboardingUrl($request->user()));
        } catch (ConnectException $e) {
            return $this->home()->with('error', $e->getMessage());
        }
    }

    public function returned(Request $request, ConnectOnboardingService $connect): RedirectResponse
    {
        $user = $request->user();

        if ($user->sellerProfile === null || blank($user->sellerProfile->stripe_account_id)) {
            return redirect()->route('selling.connect');
        }

        try {
            $profile = $connect->refreshStatus($user);
        } catch (ConnectException $e) {
            return $this->home()->with('error', $e->getMessage());
        }

        return $profile->canReceivePayouts()
            ? $this->home()->with('success', 'Payouts are set up. You can now sell paid items.')
            : $this->home()->with('info', $profile->details_submitted
                ? 'Stripe is still verifying your details. We will e-mail you when you can sell.'
                : 'Your payout setup is not finished yet. Continue where you left off.');
    }

    public function refresh(Request $request, ConnectOnboardingService $connect): RedirectResponse
    {
        return $this->start($request, $connect);
    }

    public function dashboardLink(Request $request, ConnectOnboardingService $connect): RedirectResponse
    {
        try {
            return redirect()->away($connect->loginUrl($request->user()));
        } catch (ConnectException $e) {
            return $this->home()->with('error', $e->getMessage());
        }
    }

    private function home(): RedirectResponse
    {
        return redirect()->route(Route::has('selling.dashboard') ? 'selling.dashboard' : 'dashboard');
    }
}
