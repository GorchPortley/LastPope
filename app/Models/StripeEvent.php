<?php

namespace App\Models;

use Database\Factories\StripeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe webhook event we have received (primary key = Stripe's event id), for de-duplication.
 */
class StripeEvent extends Model
{
    /** @use HasFactory<StripeEventFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'type', 'processed_at'];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function markProcessed(): static
    {
        $this->forceFill(['processed_at' => now()])->save();

        return $this;
    }
}
