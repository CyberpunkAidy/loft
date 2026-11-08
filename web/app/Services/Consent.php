<?php

namespace App\Services;

use App\Models\StudioUser;
use Illuminate\Support\Facades\DB;

/**
 * Marketing email consent, per studio membership. Opting in records when and where (the join form,
 * the profile page); opting out records when. Each change is also a domain event, so the history of
 * consent is kept, not just its current state. Transactional email (bookings, receipts) never needs it.
 */
class Consent
{
    public function __construct(private readonly DomainEvents $events) {}

    /** $source: join, profile or unsubscribe_link. */
    public function setMarketingEmail(StudioUser $membership, bool $allow, string $source): void
    {
        if ($allow === $membership->allowsMarketingEmail()) {
            return;
        }
        DB::transaction(function () use ($membership, $allow, $source) {
            $membership->update($allow
                ? ['email_marketing_consent_at' => now(), 'email_marketing_consent_source' => $source, 'email_unsubscribed_at' => null]
                : ['email_unsubscribed_at' => now()]);
            $this->events->record(
                $allow ? 'consent.email_marketing_granted' : 'consent.email_marketing_withdrawn',
                $membership->studio_id,
                ['source' => $source],
                $membership->user_id,
            );
        });
    }
}
