<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every studio email goes through here. Each is logged in message_deliveries under a dedupe key, so
 * it goes out once however often the code that sends it runs (a retried webhook, an hourly job).
 * Marketing email goes only to members who opted in, with an unsubscribe link and the one-click
 * List-Unsubscribe headers; to anyone else it's logged as suppressed and not sent.
 */
class Messages
{
    /**
     * Queue $message to $user. Returns the delivery, or null if there was no one to send to or the
     * dedupe key was already used. $dedupeKey null means "never a duplicate".
     */
    public function email(?User $user, StudioMessage $message, ?string $dedupeKey = null, string $category = 'transactional', ?int $domainEventId = null, ?int $automationId = null): ?MessageDelivery
    {
        if (! $user) {
            return null;
        }
        $studio = $message->studio;
        $marketing = $category === 'marketing';
        // A demo studio (decision 44) never emails anyone: logged as suppressed, never sent.
        $suppressed = $studio->isDemo()
            || ($marketing && ! StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->first()?->allowsMarketingEmail());
        $key = $dedupeKey ?? $message->kind.':'.Str::uuid();

        // ON CONFLICT DO NOTHING: safe inside a transaction on Postgres, unlike catching a violation.
        $inserted = MessageDelivery::query()->insertOrIgnore([
            'studio_id' => $studio->id,
            'user_id' => $user->id,
            'domain_event_id' => $domainEventId,
            'automation_id' => $automationId,
            'channel' => 'email',
            'category' => $category,
            'kind' => $message->kind,
            'dedupe_key' => $key,
            'status' => $suppressed ? 'suppressed' : 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return null;
        }
        $delivery = MessageDelivery::where('dedupe_key', $key)->firstOrFail();
        if ($suppressed) {
            return $delivery;
        }

        $message->deliveryId = $delivery->id;
        if ($marketing) {
            $message->unsubscribeUrl = $this->unsubscribeUrl($studio, $user);
        }
        Mail::to($user->email, $user->name)->send($message);

        return $delivery;
    }

    /** A link that unsubscribes this member from this studio's marketing email, without signing in. */
    public function unsubscribeUrl(Studio $studio, User $user): string
    {
        return URL::signedRoute('studio.unsubscribe', ['studio' => $studio, 'member' => $user]);
    }

    /** The mailer sent it (listens for MessageSent). */
    public function markSent(MessageSent $event): void
    {
        $id = $event->data['deliveryId'] ?? null;
        if (! $id) {
            return;
        }
        $resendId = $event->sent->getOriginalMessage()->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString();
        MessageDelivery::whereKey($id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_id' => $resendId ?: $event->sent->getMessageId(),
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    /** The queued email gave up (called from StudioMessage::failed). */
    public function markFailed(int $deliveryId, Throwable $e): void
    {
        MessageDelivery::whereKey($deliveryId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
    }
}
