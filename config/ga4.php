<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Analytics 4 (docs/PLAN.md 12)
    |--------------------------------------------------------------------------
    |
    | measurement_id   the GA4 web stream ("G-..."). Empty = no tag, no consent banner and no server calls at all
    |                  (this is how dev stays out of the numbers).
    | api_secret       Measurement Protocol API secret of that stream; without it the server-side purchase and refund
    |                  events are not sent (the browser tag still works).
    | consent_cookie   first-party cookie that remembers the visitor's choice: "granted" or "denied".
    | collect_url      Measurement Protocol endpoint.
    |
    */

    'measurement_id' => env('GA4_MEASUREMENT_ID'),
    'api_secret' => env('GA4_API_SECRET'),
    'consent_cookie' => 'sdl_consent',
    'collect_url' => 'https://www.google-analytics.com/mp/collect',

];
