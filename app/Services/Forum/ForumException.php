<?php

namespace App\Services\Forum;

use RuntimeException;

/** A forum call failed. The exception code is the HTTP status when Flarum answered with an error. */
class ForumException extends RuntimeException
{
    /** Flarum limits every member to one new post or discussion per 10 seconds (HTTP 429). */
    public function isThrottled(): bool
    {
        return $this->getCode() === 429;
    }
}
