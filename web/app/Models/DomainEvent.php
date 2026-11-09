<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that happened at a studio: written in the same transaction as the change (the outbox),
 * then relayed to consumers (App\Services\DomainEvents). The payload holds IDs and facts, never names
 * or email addresses, so the history can stay when an account is deleted.
 */
#[Fillable(['uuid', 'studio_id', 'type', 'user_id', 'attendee_id', 'payload', 'occurred_at', 'relayed_at', 'relay_attempts', 'relay_error'])]
class DomainEvent extends Model
{
    public $timestamps = false;

    /** Every type, and how the member timeline describes it. */
    public const TYPES = [
        'member.joined' => 'Joined',
        'booking.booked' => 'Booked',
        'booking.waitlisted' => 'Joined the waitlist',
        'booking.promoted' => 'Moved in from the waitlist',
        'booking.cancelled' => 'Cancelled',
        'booking.attended' => 'Attended',
        'booking.no_show' => 'No-show',
        'booking.check_in_undone' => 'Check-in undone',
        'session.cancelled' => 'Class cancelled by the studio',
        'entitlement.granted' => 'Got a plan',
        'entitlement.cancel_scheduled' => 'Membership set to end',
        'entitlement.cancel_unscheduled' => 'Membership no longer ending',
        'entitlement.ended' => 'Plan ended',
        'payment.failed' => 'Membership payment failed',
        'checkout.failed' => 'Payment didn’t go through',
        'order.paid' => 'Paid',
        'order.voided' => 'Payment voided',
        'waiver.signed' => 'Signed a waiver',
        'consent.email_marketing_granted' => 'Opted in to marketing emails',
        'consent.email_marketing_withdrawn' => 'Opted out of marketing emails',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'relayed_at' => 'datetime',
            'relay_attempts' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /** For the member timeline: "Attended (first class)", "Cancelled late", "Got 5-Class Pack"… */
    public function label(): string
    {
        $p = $this->payload ?? [];
        $label = self::TYPES[$this->type] ?? $this->type;

        return match ($this->type) {
            'booking.cancelled' => match ($p['by'] ?? null) {
                'studio' => 'Class cancelled by the studio',
                'staff' => 'Removed by staff',
                'waitlist' => 'Dropped from the waitlist (no plan covered it)',
                default => ! empty($p['late']) ? 'Cancelled late' : 'Cancelled',
            },
            'booking.attended' => ! empty($p['first_visit']) ? 'Attended (first class)' : $label,
            'entitlement.granted' => 'Got '.($p['plan_name'] ?? 'a plan').match ($p['source'] ?? 'stripe') {
                'stripe' => ' (paid online)',
                'comp' => ' (comped)',
                'staff' => ' (given by staff)',
                default => ' (paid in person: '.strtolower(Payment::METHODS[$p['source']] ?? $p['source']).')',
            },
            'entitlement.ended' => ($p['plan_name'] ?? 'Plan').' '.match ($p['reason'] ?? null) {
                'expired' => 'expired',
                'voided' => 'voided',
                default => 'ended',
            },
            'order.paid' => 'Paid '.Money::format((int) ($p['total_cents'] ?? 0), $p['currency'] ?? 'usd')
                .(! empty($p['items']) ? ' for '.implode(' + ', $p['items']) : '').(isset($p['number']) ? ' (#'.$p['number'].')' : ''),
            'order.voided' => 'Payment voided'.(isset($p['number']) ? ' (#'.$p['number'].')' : ''),
            'waiver.signed' => 'Signed '.($p['waiver_title'] ?? 'a waiver').(isset($p['version']) ? ' (v'.$p['version'].')' : ''),
            default => $label,
        };
    }

    /** "Kids Aerial Silks · Tue, Nov 10, 4:30 PM", for events about a class. */
    public function classLabel(Studio $studio): ?string
    {
        $p = $this->payload ?? [];
        if (empty($p['program_title'])) {
            return null;
        }

        return $p['program_title'].(! empty($p['starts_at']) ? ' · '.$studio->local(Carbon::parse($p['starts_at']))->format('D, M j, g:i A') : '');
    }
}
