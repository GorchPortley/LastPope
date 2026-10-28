<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        'theme',
        'flarum_session',
        'flarum_remember',
        // Written by the browser (GA4 tag and the consent banner), read at checkout: see App\Support\Analytics\Ga4.
        '_ga',
        'sdl_consent',
    ];
}
