<?php

namespace App\Exceptions\Marketplace;

use RuntimeException;
use Throwable;

/** Stripe Connect onboarding or dashboard access failed. getMessage() is safe to show to the seller. */
class ConnectException extends RuntimeException
{
    public static function notOnboarded(): self
    {
        return new self('Finish setting up payouts with Stripe first.');
    }

    public static function gateway(Throwable $previous): self
    {
        return new self('We could not reach Stripe. Please try again in a moment.', 0, $previous);
    }
}
