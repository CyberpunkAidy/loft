<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who signed which waiver version for which attendee, from where. Minors are signed by the account
 * holder. A signature outlives the account: deleting the attendee or signer unlinks it, and the
 * names, version and hash of the exact text keep it meaningful (decision 20).
 */
#[Fillable([
    'studio_id', 'waiver_id', 'waiver_version', 'waiver_version_id', 'body_sha256', 'attendee_id', 'attendee_name',
    'signed_by_user_id', 'signed_name', 'signer_relationship', 'signature_path', 'ip', 'user_agent', 'signed_at',
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(WaiverVersion::class, 'waiver_version_id');
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
