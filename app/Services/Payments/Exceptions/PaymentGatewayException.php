<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The payment provider could not do what we asked (network, API error, not configured).
 * The provider's own exception is kept as $previous; the message is safe to log, not to show.
 */
class PaymentGatewayException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Stripe is not configured: set STRIPE_SECRET_KEY.');
    }

    /**
     * Trying again will not help: Stripe refused the request itself (unknown account, no permission for it, bad key) rather than
     * failing to answer (network, rate limit, server error).
     */
    public function isPermanent(): bool
    {
        $previous = $this->getPrevious();

        // (a rate limit is an InvalidRequestException in stripe-php, but it passes)
        if ($previous instanceof \Stripe\Exception\RateLimitException) {
            return false;
        }

        return $previous instanceof \Stripe\Exception\InvalidRequestException
            || $previous instanceof \Stripe\Exception\PermissionException
            || $previous instanceof \Stripe\Exception\AuthenticationException;
    }

    public static function wrap(Throwable $e): self
    {
        return new self($e->getMessage(), (int) $e->getCode(), $e);
    }
}
