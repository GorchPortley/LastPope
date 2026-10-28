<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A server-side analytics event that was (or is about to be) sent; `key` is unique per order / refund. */
class AnalyticsEvent extends Model
{
    protected $fillable = ['key', 'sent_at'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
