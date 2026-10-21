<?php

namespace App\Exceptions\Marketplace;

use RuntimeException;

/** An order action (ship, confirm, refund) was attempted in a state that does not allow it. Message is safe to show. */
class InvalidOrderStateException extends RuntimeException
{
}
