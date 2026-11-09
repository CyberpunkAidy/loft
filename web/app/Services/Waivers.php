<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\Program;
use App\Models\Studio;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverOverride;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Waiver rules: which waivers a class needs, whether an attendee has signed them, signing, staff
 * overrides, and saving waiver text so a version someone signed is never edited in place.
 *
 * A class needs every waiver in force that applies to every class, plus the ones attached to its
 * program (an aerial liability waiver, say). A signature counts for the waiver's current version
 * until it expires (the waiver's own validity, or the studio's setting).
 */
class Waivers
{
    public function __construct(private readonly DomainEvents $events) {}

    /**
     * The waivers someone must have signed to attend $program, or, with no program, any class.
     *
     * @return Collection<int, Waiver>
     */
    public function required(Studio $studio, ?Program $program = null): Collection
    {
        return $studio->activeWaivers()->filter(fn (Waiver $w) => $w->appliesTo($program))->values()->toBase();
    }

    /**
     * One waiver's status for one attendee.
     *
     * @return array{waiver: Waiver, signed: bool, expired: bool, signed_at: ?Carbon, expires_at: ?Carbon}
     */
    public function statusOf(Studio $studio, Attendee $attendee, Waiver $waiver): array
    {
        $signature = WaiverSignature::query()
            ->where('attendee_id', $attendee->id)
            ->where('waiver_id', $waiver->id)
            ->where('waiver_version', $waiver->version)
            ->latest('signed_at')
            ->first();
        if (! $signature) {
            return ['waiver' => $waiver, 'signed' => false, 'expired' => false, 'signed_at' => null, 'expires_at' => null];
        }

        $validity = $waiver->validity_days ?? (int) $studio->setting('waiver_validity_days');
        $expires = $validity > 0 ? $signature->signed_at->copy()->addDays($validity) : null;
        $expired = $expires !== null && ! $expires->isFuture();

        return ['waiver' => $waiver, 'signed' => ! $expired, 'expired' => $expired, 'signed_at' => $signature->signed_at, 'expires_at' => $expires];
    }

    /**
     * Everything an attendee needs for $program (or any class, with none).
     *
     * @return array{required: bool, signed: bool, waivers: list<array>, missing: Collection<int, Waiver>, signed_at: ?Carbon, expires_at: ?Carbon}
     */
    public function statusFor(Studio $studio, Attendee $attendee, ?Program $program = null): array
    {
        $statuses = $this->required($studio, $program)->map(fn (Waiver $w) => $this->statusOf($studio, $attendee, $w));
        $missing = $statuses->reject(fn (array $s) => $s['signed'])->pluck('waiver')->values();

        return [
            'required' => $statuses->isNotEmpty(),
            'signed' => $missing->isEmpty(),
            'waivers' => $statuses->all(),
            'missing' => $missing,
            'signed_at' => $statuses->pluck('signed_at')->filter()->max(),
            'expires_at' => $statuses->pluck('expires_at')->filter()->min(),
        ];
    }

    public function isSigned(Studio $studio, Attendee $attendee, ?Program $program = null): bool
    {
        return $this->statusFor($studio, $attendee, $program)['signed'];
    }

    /** The next waiver $attendee has to sign for $program, if any. */
    public function nextUnsigned(Studio $studio, Attendee $attendee, ?Program $program = null): ?Waiver
    {
        return $this->statusFor($studio, $attendee, $program)['missing']->first();
    }

    /**
     * The first waiver in force that $attendee hasn't signed and needs: one for every class, or one for
     * a program they're booked into. Signing goes from one to the next until this is null.
     */
    public function nextToSign(Studio $studio, Attendee $attendee): ?Waiver
    {
        foreach ($studio->activeWaivers() as $waiver) {
            if (! $this->statusOf($studio, $attendee, $waiver)['signed'] && $this->needs($attendee, $waiver)) {
                return $waiver;
            }
        }

        return null;
    }

    /** Whether $attendee needs $waiver now: it's for every class, or for a program they're booked into. */
    public function needs(Attendee $attendee, Waiver $waiver): bool
    {
        return $waiver->appliesToEveryClass() || $attendee->bookings()->whereIn('status', ['booked', 'waitlisted'])
            ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>', now())->whereIn('program_id', $waiver->programs->pluck('id')))
            ->exists();
    }

    /** Whether a booking's attendee may attend: everything its class needs is signed, or staff let them in. */
    public function clears(Booking $booking): bool
    {
        if (WaiverOverride::where('booking_id', $booking->id)->exists()) {
            return true;
        }

        return $this->isSigned($booking->studio, $booking->attendee, $booking->classSession->program);
    }

    /** Staff let someone in without a signature: record who and why. */
    public function override(Booking $booking, User $staff, string $reason): WaiverOverride
    {
        return WaiverOverride::updateOrCreate(
            ['booking_id' => $booking->id],
            ['studio_id' => $booking->studio_id, 'staff_user_id' => $staff->id, 'reason' => $reason],
        );
    }

    /** The current version's recorded text (recorded on first use for waivers older than versions). */
    public function currentVersion(Waiver $waiver): WaiverVersion
    {
        return WaiverVersion::firstOrCreate(['waiver_id' => $waiver->id, 'version' => $waiver->version], [
            'studio_id' => $waiver->studio_id,
            'title' => $waiver->title,
            'body' => $waiver->body,
            'body_sha256' => WaiverVersion::hash($waiver->body),
        ]);
    }

    /**
     * Save a waiver's text. Until someone signs the current version it's edited in place; after that,
     * changed text publishes the next version, so nobody's signature ever points at words they didn't
     * see. $newVersion publishes one anyway (everyone signs again). Returns whether it published.
     */
    public function saveText(Waiver $waiver, string $title, string $body, bool $newVersion = false): bool
    {
        $changed = $title !== $waiver->title || $body !== $waiver->body;
        $signed = $waiver->signatures()->where('waiver_version', $waiver->version)->exists();
        $publish = $newVersion || ($changed && $signed);

        DB::transaction(function () use ($waiver, $title, $body, $publish) {
            $waiver->update(['title' => $title, 'body' => $body, 'version' => $waiver->version + ($publish ? 1 : 0)]);
            WaiverVersion::updateOrCreate(['waiver_id' => $waiver->id, 'version' => $waiver->version], [
                'studio_id' => $waiver->studio_id,
                'title' => $title,
                'body' => $body,
                'body_sha256' => WaiverVersion::hash($body),
            ]);
        });

        return $publish;
    }

    /** Sign $waiver's current version for an attendee. */
    public function sign(Studio $studio, Attendee $attendee, Waiver $waiver, User $signer, string $signedName, ?string $signaturePng, ?string $ip, ?string $userAgent): WaiverSignature
    {
        $version = $this->currentVersion($waiver);

        return DB::transaction(fn () => tap(WaiverSignature::create([
            'studio_id' => $studio->id,
            'waiver_id' => $waiver->id,
            'waiver_version' => $waiver->version,
            'waiver_version_id' => $version->id,
            'body_sha256' => $version->body_sha256,
            'attendee_id' => $attendee->id,
            'attendee_name' => $attendee->name,
            'signed_by_user_id' => $signer->id,
            'signed_name' => $signedName,
            'signer_relationship' => match (true) {
                $attendee->is_self => 'self',
                $attendee->isMinor((int) $studio->setting('minor_age')) => 'guardian',
                default => 'account_holder',
            },
            'signature_path' => $signaturePng ? $this->storeSignatureImage($studio, $attendee, $signaturePng) : null,
            'ip' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
            'signed_at' => now(),
        ]), fn (WaiverSignature $signature) => $this->events->record('waiver.signed', $studio, [
            'signature_id' => $signature->id,
            'waiver_id' => $waiver->id,
            'waiver_title' => $version->title,
            'version' => $version->version,
            'relationship' => $signature->signer_relationship,
        ], $signer->id, $attendee->id)));
    }

    /**
     * Store a drawn signature (a data:image/png;base64 URL, up to 512 KB) on the private disk.
     * Returns the stored path, or null if it isn't a PNG.
     */
    public function storeSignatureImage(Studio $studio, Attendee $attendee, string $dataUrl): ?string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return null;
        }
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($binary === false || strlen($binary) > 512 * 1024 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $path = sprintf('signatures/%d/sig-%d-%s.png', $studio->id, $attendee->id, Str::random(16));
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
