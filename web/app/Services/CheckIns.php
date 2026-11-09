<?php

namespace App\Services;

use App\Models\AccessCredential;
use App\Models\AccessDevice;
use App\Models\AccessEvent;
use App\Models\Attendee;
use App\Models\Booking;
use App\Support\BookingException;
use App\Support\CheckInResult;
use Illuminate\Support\Carbon;

/**
 * Contactless check-in (roadmap step 8). A kiosk or reader reports what it scanned: a check-in QR
 * code (App\Services\CheckInCodes) or a tag's UID. Whoever it is gets checked in to their class:
 * the one they're booked into that starts within OPENS_MINUTES_BEFORE, or has started and not ended.
 * Checking in goes through Bookings::mark, so waivers are enforced as at the front desk (a kiosk
 * can't override). Every scan is logged in access_events, whether it let them in or not.
 */
class CheckIns
{
    public const OPENS_MINUTES_BEFORE = 60;

    /** When the scan being handled happened. */
    private Carbon $at;

    public function __construct(
        private readonly CheckInCodes $codes,
        private readonly Bookings $bookings,
    ) {}

    /** @param  Carbon|null  $at  when it was scanned (a device that was offline sends it later); now if null */
    public function scan(AccessDevice $device, string $raw, ?Carbon $at = null): CheckInResult
    {
        $studio = $device->studio;
        $at = $this->at = $at ?? now();
        $method = $this->codes->isCode($raw) ? 'qr' : 'tag';
        $credential = null;

        if ($method === 'qr') {
            ['attendee' => $attendee, 'expired' => $expired] = $this->codes->verify($studio, $raw, $at->getTimestamp());
            if (! $attendee) {
                return $expired
                    ? $this->denied($device, $method, 'expired_code', 'That code has expired. Refresh it and scan again.')
                    : $this->denied($device, $method, 'unknown_credential', 'We don’t recognise that code. Please see the front desk.');
            }
        } else {
            $credential = $studio->accessCredentials()->with('attendee')->whereNull('revoked_at')
                ->where('value_hash', AccessCredential::hash($raw))->first();
            $attendee = $credential?->attendee;
            if (! $attendee || $attendee->status !== 'active') {
                return $this->denied($device, $method, 'unknown_credential', 'We don’t recognise that card. Please see the front desk.');
            }
            $credential->update(['last_used_at' => now()]);
        }

        $booking = $this->bookingFor($attendee, $at);
        $name = $attendee->first_name;
        if (! $booking) {
            return $this->denied($device, $method, 'no_booking', "{$name} isn’t booked into a class right now. Please see the front desk.", $attendee, null, $credential);
        }
        $class = $booking->classSession->program->title;
        if ($booking->status === 'waitlisted') {
            return $this->denied($device, $method, 'waitlisted', "{$name} is on the waitlist for {$class}, not booked yet. Please see the front desk.", $attendee, $booking, $credential);
        }
        if ($booking->status === 'attended') {
            return $this->granted($device, $method, 'already_checked_in', "{$name} is already checked in for {$class}.", $attendee, $booking, $credential);
        }
        try {
            $this->bookings->mark($booking, 'attended');
        } catch (BookingException) {
            return $this->denied($device, $method, 'waiver_required', "{$name} needs to sign the waiver for {$class} first. Please see the front desk.", $attendee, $booking, $credential);
        }
        $time = $studio->local($booking->classSession->starts_at)->format('g:i A');

        return $this->granted($device, $method, 'checked_in', "Welcome, {$name}! You’re checked in for {$class} at {$time}.", $attendee, $booking->fresh(), $credential);
    }

    /**
     * Give someone on a member's account a tag. A tag that was taken back can be given out again; one
     * someone else is using can't.
     *
     * @throws \InvalidArgumentException with a message for staff
     */
    public function addTag(Attendee $attendee, string $uid, ?string $label = null): AccessCredential
    {
        $normalized = AccessCredential::normalize($uid);
        if (strlen($normalized) < 4) {
            throw new \InvalidArgumentException('That doesn’t look like a tag number. Scan the tag again.');
        }
        $existing = AccessCredential::with('attendee')->where('studio_id', $attendee->studio_id)->where('value_hash', AccessCredential::hash($normalized))->first();
        if ($existing && ! $existing->isRevoked() && $existing->attendee_id !== $attendee->id) {
            throw new \InvalidArgumentException("That tag is already {$existing->attendee->first_name} {$existing->attendee->last_name}’s. Take it back from them first.");
        }
        $values = ['attendee_id' => $attendee->id, 'kind' => 'tag', 'value_last4' => substr($normalized, -4), 'label' => $label, 'revoked_at' => null];
        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return AccessCredential::create($values + ['studio_id' => $attendee->studio_id, 'value_hash' => AccessCredential::hash($normalized)]);
    }

    /** Their booking to check in to at $at: not yet checked in first, then the soonest. */
    private function bookingFor(Attendee $attendee, Carbon $at): ?Booking
    {
        return Booking::with('classSession.program')
            ->where('studio_id', $attendee->studio_id)->where('attendee_id', $attendee->id)
            ->whereIn('status', ['booked', 'attended', 'waitlisted'])
            ->where('online_spot', false) // online people join from home, not at the door
            ->whereHas('classSession', fn ($q) => $q->where('status', 'scheduled')->where('delivery', '!=', 'online')
                ->where('starts_at', '<=', $at->copy()->addMinutes(self::OPENS_MINUTES_BEFORE))
                ->where('ends_at', '>=', $at))
            ->get()
            ->sortBy(fn (Booking $b) => [['booked' => 0, 'attended' => 1][$b->status] ?? 2, $b->classSession->starts_at->getTimestamp()])
            ->first();
    }

    private function granted(AccessDevice $device, string $method, string $reason, string $message, Attendee $attendee, Booking $booking, ?AccessCredential $credential): CheckInResult
    {
        return new CheckInResult(true, $reason, $message, $attendee, $booking, $this->log($device, $method, 'granted', $reason, $attendee, $booking, $credential));
    }

    private function denied(AccessDevice $device, string $method, string $reason, string $message, ?Attendee $attendee = null, ?Booking $booking = null, ?AccessCredential $credential = null): CheckInResult
    {
        return new CheckInResult(false, $reason, $message, $attendee, $booking, $this->log($device, $method, 'denied', $reason, $attendee, $booking, $credential));
    }

    private function log(AccessDevice $device, string $method, string $result, string $reason, ?Attendee $attendee, ?Booking $booking, ?AccessCredential $credential): AccessEvent
    {
        return AccessEvent::create([
            'studio_id' => $device->studio_id,
            'access_device_id' => $device->id,
            'access_credential_id' => $credential?->id,
            'attendee_id' => $attendee?->id,
            'booking_id' => $booking?->id,
            'method' => $method,
            'result' => $result,
            'reason' => $reason,
            'occurred_at' => $this->at,
            'created_at' => now(),
        ]);
    }
}
