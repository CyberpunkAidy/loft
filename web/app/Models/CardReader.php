<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Stripe Terminal card reader at the studio's front desk, registered on the studio's Stripe account. */
#[Fillable(['studio_id', 'stripe_reader_id', 'label', 'device_type', 'serial_number'])]
class CardReader extends Model
{
    public const DEVICE_TYPES = [
        'bbpos_wisepos_e' => 'BBPOS WisePOS E',
        'stripe_s700' => 'Stripe Reader S700',
        'simulated_wisepos_e' => 'Simulated WisePOS E (test mode)',
        'simulated_stripe_s700' => 'Simulated S700 (test mode)',
    ];

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function deviceLabel(): string
    {
        return self::DEVICE_TYPES[$this->device_type] ?? ($this->device_type ? ucwords(str_replace('_', ' ', $this->device_type)) : 'Card reader');
    }
}
