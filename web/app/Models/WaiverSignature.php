<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who signed which waiver version for which attendee, from where. Minors are signed by the account holder. */
#[Fillable([
    'studio_id', 'waiver_id', 'waiver_version', 'attendee_id', 'signed_by_user_id', 'signed_name',
    'signature_path', 'ip', 'user_agent', 'signed_at',
])]
class WaiverSignature extends Model
{
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'waiver_version' => 'integer',
        ];
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }
}
