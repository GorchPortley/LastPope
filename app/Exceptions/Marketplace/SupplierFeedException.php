<?php

namespace App\Exceptions\Marketplace;

use RuntimeException;

/** A supplier's stock feed could not be read or understood. The message is stored on the supplier and safe to show to admins. */
class SupplierFeedException extends RuntimeException
{
}
