<?php

namespace App\Services\Marketplace;

use App\Exceptions\Marketplace\ConnectException;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\PaymentGateway;

/**
 * Seller side of Stripe Connect Express (docs/PLAN.md 3.3).
 *
 *     $url = $connect->onboardingUrl($user);      // creates profile + connected account on first use
 *     return redirect()->away($url);
 *
 * Stripe sends the seller back to route('selling.connect.return'); the account.updated webhook
 * (and refreshStatus()) then flips charges_enabled / payouts_enabled / details_submitted on the
 * profile, which is what User::canReceivePayouts() reads. Gateway failures surface as ConnectException.
 */
class ConnectOnboardingService
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    /**
     * The user's seller profile, created on first use. $country (ISO alpha-2) can only be chosen
     * before the Stripe account exists: Stripe fixes it at creation.
     */
    public function ensureProfile(User $user, ?string $shopName = null, ?string $country = null): SellerProfile
    {
        $profile = SellerProfile::firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'shop_name' => filled($shopName) ? $shopName : ($user->name ?: 'My shop'),
                'country' => strtoupper(filled($country) ? $country : (string) config('marketplace.default_country', 'US')),
            ],
        );

        $user->setRelation('sellerProfile', $profile);

        return $profile;
    }

    /** Profile plus a connected Stripe account (created if missing). */
    public function ensureAccount(User $user, ?string $shopName = null, ?string $country = null): SellerProfile
    {
        $profile = $this->ensureProfile($user, $shopName, $country);

        if (blank($profile->stripe_account_id)) {
            $profile->setRelation('user', $user);

            try {
                $accountId = $this->gateway->createConnectedAccount($profile);
            } catch (PaymentGatewayException $e) {
                report($e);

                throw ConnectException::gateway($e);
            }

            $profile->forceFill(['stripe_account_id' => $accountId])->save();
        }

        return $profile;
    }

    /** Stripe-hosted page to start or continue onboarding. */
    public function onboardingUrl(User $user): string
    {
        $profile = $this->ensureAccount($user);

        try {
            return $this->gateway->createOnboardingLink(
                $profile->stripe_account_id,
                route('selling.connect.return'),
                route('selling.connect.refresh'),
            );
        } catch (PaymentGatewayException $e) {
            report($e);

            throw ConnectException::gateway($e);
        }
    }

    /**
     * One-time link to the seller's Express dashboard on Stripe.
     *
     * @throws ConnectException  when onboarding has not been submitted yet
     */
    public function loginUrl(User $user): string
    {
        $profile = $user->sellerProfile;

        if ($profile === null || blank($profile->stripe_account_id) || ! $profile->details_submitted) {
            // The local flag may be stale (the webhook can lag); ask Stripe once before refusing.
            if ($profile !== null && filled($profile->stripe_account_id)) {
                $profile = $this->refreshStatus($profile);
            }

            if ($profile === null || blank($profile->stripe_account_id) || ! $profile->details_submitted) {
                throw ConnectException::notOnboarded();
            }
        }

        try {
            return $this->gateway->createLoginLink($profile->stripe_account_id);
        } catch (PaymentGatewayException $e) {
            report($e);

            throw ConnectException::gateway($e);
        }
    }

    /** Pull the account's flags from Stripe into the profile (after the seller returns from onboarding). */
    public function refreshStatus(SellerProfile|User $who): SellerProfile
    {
        $profile = $who instanceof User ? $who->sellerProfile : $who;

        if ($profile === null || blank($profile->stripe_account_id)) {
            throw ConnectException::notOnboarded();
        }

        try {
            $status = $this->gateway->retrieveAccountStatus($profile->stripe_account_id);
        } catch (PaymentGatewayException $e) {
            report($e);

            throw ConnectException::gateway($e);
        }

        return $this->applyStatus($profile, $status);
    }

    /**
     * Store Stripe's flags on the profile. Used by refreshStatus() and the account.updated webhook.
     *
     * @param  array{charges_enabled?: bool, payouts_enabled?: bool, details_submitted?: bool}  $status
     */
    public function applyStatus(SellerProfile $profile, array $status): SellerProfile
    {
        $profile->forceFill([
            'charges_enabled' => (bool) ($status['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($status['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($status['details_submitted'] ?? false),
        ])->save();

        return $profile;
    }
}
