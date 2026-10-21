<?php

namespace App\Services\Forum;

use RuntimeException;

/** Flarum refused to create an account because the e-mail address already belongs to one. */
class EmailAlreadyTaken extends RuntimeException
{
}
