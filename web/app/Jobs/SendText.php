<?php

namespace App\Jobs;

use App\Models\MessageDelivery;
use App\Models\StudioUser;
use App\Services\Consent;
use App\Services\Texts;
use App\Services\Twilio\TwilioClient;
use App\Services\Twilio\TwilioException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one logged text through the studio's Twilio account (decision 48). By the time it runs,
 * the member may have replied STOP or the studio disconnected Twilio: then it isn't sent. Twilio
 * outages are retried; anything Twilio refuses is marked failed with the reason in plain words.
 */
class SendText implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @param  ?string  $consent  'transactional' or 'marketing' to check the member still agrees, null when it isn't needed (tests, the opt-in confirmation) */
    public function __construct(
        public int $deliveryId,
        public string $body,
        public ?string $to = null,
        public ?string $consent = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TwilioClient $twilio, Consent $consents): void
    {
        $delivery = MessageDelivery::with(['studio', 'user'])->find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'queued') {
            return;
        }
        $studio = $delivery->studio;
        if (! $studio->textsReady()) {
            $delivery->update(['status' => 'failed', 'error' => 'Texts were turned off before it went out']);

            return;
        }
        $to = $this->to ?? $delivery->user?->phone_e164;
        if ($this->consent !== null) {
            $membership = $delivery->user ? StudioUser::where('studio_id', $studio->id)->where('user_id', $delivery->user->id)->first() : null;
            if (! $to || ! Texts::allows($membership, $this->consent)) {
                $delivery->update(['status' => 'suppressed']);

                return;
            }
        }
        if (! $to) {
            $delivery->update(['status' => 'failed', 'error' => 'No mobile number']);

            return;
        }

        try {
            $message = $twilio->send($studio, $to, $this->body, route('twilio.status', ['studioId' => $studio->id, 'delivery' => $delivery->id]));
        } catch (TwilioException $e) {
            if ($e->isTemporary()) {
                throw $e; // tried again later; failed() records it if it never goes
            }
            if ($e->twilioCode === 21610) {
                $consents->textsStopped($studio, $to, 'carrier');
            }
            $delivery->update(['status' => 'failed', 'error' => Texts::error($e->twilioCode, $e->getMessage())]);

            return;
        }
        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $message['sid'] ?? null, 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        MessageDelivery::whereKey($this->deliveryId)->where('status', 'queued')->update([
            'status' => 'failed',
            'error' => mb_substr($e instanceof TwilioException ? Texts::error($e->twilioCode, $e->getMessage()) : (string) $e?->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }
}
