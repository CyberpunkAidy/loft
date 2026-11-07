<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Webhook log, unique by Stripe event id, so redelivered events are processed once. */
#[Fillable(['event_id', 'type', 'account', 'studio_id', 'received_at', 'processed_at', 'error', 'payload'])]
class StripeEvent extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
