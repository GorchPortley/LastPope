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

    public static function wrap(Throwable $e): self
    {
        return new self($e->getMessage(), (int) $e->getCode(), $e);
    }
}
