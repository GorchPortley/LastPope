<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** A webhook body failed signature verification or could not be parsed. Answer with HTTP 400. */
class InvalidWebhookException extends RuntimeException
{
}
