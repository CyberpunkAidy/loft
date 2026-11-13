<?php

namespace App\Services;

use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What members agreed to hear from each studio, per studio membership: marketing email, and texts
 * (decision 48), with booking texts and marketing texts kept apart as the TCPA treats them. Opting
 * in records when and where; each change is also a domain event, so the history of consent is kept,
 * not just its current state. Transactional email (bookings, receipts) never needs consent.
 */
class Consent
{
    /** Replies that opt a phone out of a sender's texts, and back in (Twilio's defaults). */
    public const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];

    public const START_WORDS = ['START', 'UNSTOP', 'YES'];

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

    /**
     * Texts about bookings ($updates) and news and offers by text ($marketing). $source: join,
     * profile or app. Someone who replied STOP can only opt back in by texting START (their carrier
     * blocks the studio's texts until then), so a grant from them is ignored. Returns what was newly
     * turned on ('updates', 'marketing'), for the confirmation text.
     *
     * @return list<string>
     */
    public function setTexts(StudioUser $membership, bool $updates, bool $marketing, string $source): array
    {
        if ($membership->sms_stopped_at !== null) {
            return [];
        }
        $changes = [];
        $events = [];
        $granted = [];
        foreach (['updates' => $updates, 'marketing' => $marketing] as $kind => $allow) {
            if ($allow === ($membership->{"sms_{$kind}_at"} !== null)) {
                continue;
            }
            $changes += $allow ? ["sms_{$kind}_at" => now(), "sms_{$kind}_source" => $source] : ["sms_{$kind}_at" => null, "sms_{$kind}_source" => null];
            $events[] = "consent.sms_{$kind}_".($allow ? 'granted' : 'withdrawn');
            if ($allow) {
                $granted[] = $kind;
            }
        }
        if (! $changes) {
            return [];
        }
        DB::transaction(function () use ($membership, $changes, $events, $source) {
            $membership->update($changes);
            foreach ($events as $type) {
                $this->events->record($type, $membership->studio_id, ['source' => $source], $membership->user_id);
            }
        });

        return $granted;
    }

    /**
     * $phone replied STOP to $studio's number, or Twilio refused to text it because it had ($source
     * reply_stop or carrier): every account at the studio with that number stops getting texts.
     * Returns how many memberships changed.
     */
    public function textsStopped(Studio $studio, string $phone, string $source): int
    {
        $changed = 0;
        foreach ($this->membershipsWithPhone($studio, $phone) as $membership) {
            if ($membership->sms_stopped_at !== null) {
                continue;
            }
            DB::transaction(function () use ($membership, $source) {
                $membership->update(['sms_updates_at' => null, 'sms_updates_source' => null, 'sms_marketing_at' => null, 'sms_marketing_source' => null, 'sms_stopped_at' => now()]);
                $this->events->record('consent.sms_stopped', $membership->studio_id, ['source' => $source], $membership->user_id);
            });
            $changed++;
        }

        return $changed;
    }

    /**
     * $phone replied START (or UNSTOP, YES): texts about bookings are back on for every account at the
     * studio with that number. News and offers need the box ticked again, which is their written consent.
     */
    public function textsStarted(Studio $studio, string $phone): int
    {
        $changed = 0;
        foreach ($this->membershipsWithPhone($studio, $phone) as $membership) {
            if ($membership->sms_stopped_at === null && $membership->sms_updates_at !== null) {
                continue;
            }
            DB::transaction(function () use ($membership) {
                $membership->update(['sms_stopped_at' => null] + ($membership->sms_updates_at ? [] : ['sms_updates_at' => now(), 'sms_updates_source' => 'reply_start']));
                $this->events->record('consent.sms_started', $membership->studio_id, ['source' => 'reply_start'], $membership->user_id);
            });
            $changed++;
        }

        return $changed;
    }

    /** Consent to texts is for a number: when it changes, texts stop at every studio until they opt in again. */
    public function phoneChanged(User $user): void
    {
        $memberships = StudioUser::where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNotNull('sms_updates_at')->orWhereNotNull('sms_marketing_at')->orWhereNotNull('sms_stopped_at'))->get();
        foreach ($memberships as $membership) {
            DB::transaction(function () use ($membership) {
                foreach (['updates', 'marketing'] as $kind) {
                    if ($membership->{"sms_{$kind}_at"} !== null) {
                        $this->events->record("consent.sms_{$kind}_withdrawn", $membership->studio_id, ['source' => 'phone_changed'], $membership->user_id);
                    }
                }
                $membership->update(['sms_updates_at' => null, 'sms_updates_source' => null, 'sms_marketing_at' => null, 'sms_marketing_source' => null, 'sms_stopped_at' => null]);
            });
        }
    }

    /** @return Collection<int, StudioUser> */
    private function membershipsWithPhone(Studio $studio, string $phone)
    {
        return StudioUser::where('studio_id', $studio->id)->whereIn('user_id', User::where('phone_e164', $phone)->select('id'))->get();
    }
}
