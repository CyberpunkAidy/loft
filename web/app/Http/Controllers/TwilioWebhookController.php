<?php

namespace App\Http\Controllers;

use App\Mail\TextReply;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Consent;
use App\Services\Messages;
use App\Services\Texts;
use App\Services\Twilio\TwilioSignature;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * What a studio's Twilio account tells Loft (decision 48), each request signed with that account's
 * Auth Token:
 * - incoming: a text to the studio's number. STOP and START change the sender's consent (Twilio has
 *   already blocked or unblocked them and answered); HELP is answered by Twilio; anything else is
 *   emailed to the studio's owners and admins.
 * - status: what happened to a text Loft sent: delivered, or failed and why.
 */
class TwilioWebhookController extends Controller
{
    /** At most this many replies from one number are emailed to the studio an hour. */
    private const REPLIES_PER_HOUR = 10;

    public function incoming(Request $request, int $studioId, Consent $consent, Messages $messages): Response
    {
        $studio = $this->verified($request, $studioId, route('twilio.incoming', ['studioId' => $studioId]));
        $from = (string) $request->input('From');
        $phone = Phone::e164($from) ?? $from;
        $body = trim((string) $request->input('Body'));
        $word = strtoupper($body);
        $optOut = strtoupper((string) $request->input('OptOutType')); // set when the number uses Advanced Opt-Out

        if ($optOut === 'STOP' || in_array($word, Consent::STOP_WORDS, true)) {
            $consent->textsStopped($studio, $phone, 'reply_stop');
        } elseif ($optOut === 'START' || in_array($word, Consent::START_WORDS, true)) {
            $consent->textsStarted($studio, $phone);
        } elseif ($optOut !== 'HELP' && ! in_array($word, ['HELP', 'INFO'], true)) {
            $this->forward($studio, $phone, $body, (int) $request->input('NumMedia', 0), (string) $request->input('MessageSid'), $messages);
        }

        // An empty reply: Loft doesn't answer texts itself.
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    public function status(Request $request, int $studioId, Consent $consent): Response
    {
        $deliveryId = (int) $request->query('delivery');
        $studio = $this->verified($request, $studioId, route('twilio.status', ['studioId' => $studioId, 'delivery' => $deliveryId]));
        $delivery = MessageDelivery::where('studio_id', $studio->id)->where('channel', 'sms')->find($deliveryId);
        if (! $delivery) {
            return response()->noContent();
        }
        $status = (string) $request->input('MessageStatus');
        $code = $request->filled('ErrorCode') ? (int) $request->input('ErrorCode') : null;
        $sid = $delivery->provider_message_id ?? $request->input('MessageSid');

        if (in_array($status, ['delivered', 'read'], true) && $delivery->status !== 'failed') {
            $delivery->update(['status' => 'delivered', 'provider_message_id' => $sid, 'error' => null]);
        } elseif (in_array($status, ['failed', 'undelivered'], true)) {
            $delivery->update(['status' => 'failed', 'provider_message_id' => $sid, 'error' => Texts::error($code, 'Not delivered')]);
            if ($code === 21610 && ($to = Phone::e164((string) $request->input('To')))) {
                $consent->textsStopped($studio, $to, 'carrier');
            }
        }

        return response()->noContent();
    }

    /** The studio, if Twilio signed this request with its Auth Token for exactly $url. */
    private function verified(Request $request, int $studioId, string $url): Studio
    {
        $studio = Studio::find($studioId);
        abort_unless($studio && $studio->twilio_auth_token, 404);
        abort_unless(TwilioSignature::valid((string) $studio->twilio_auth_token, $url, $request->post(), $request->header('X-Twilio-Signature')), 403);

        return $studio;
    }

    /** Email a member's reply to the studio's owners and admins, once per message. */
    private function forward(Studio $studio, string $phone, string $body, int $pictures, string $messageSid, Messages $messages): void
    {
        if (($body === '' && $pictures === 0) || ! RateLimiter::attempt("text-reply:{$studio->id}:{$phone}", self::REPLIES_PER_HOUR, fn () => true, 3600)) {
            return;
        }
        $member = User::where('phone_e164', $phone)->whereIn('id', StudioUser::where('studio_id', $studio->id)->select('user_id'))->orderBy('id')->first();
        $number = Phone::display($phone);
        $who = $member ? "{$member->name} ({$number})" : $number;

        $lines = ["{$who} texted {$studio->name}’s number:"];
        if ($body !== '') {
            $lines[] = '“'.Str::limit($body, 1600).'”';
        }
        if ($pictures > 0) {
            $lines[] = $pictures === 1 ? 'They sent a picture, which Loft doesn’t keep. You can see it in your Twilio console’s message logs.'
                : "They sent {$pictures} pictures, which Loft doesn’t keep. You can see them in your Twilio console’s message logs.";
        }
        $email = new TextReply($studio, 'Text from '.($member?->name ?? $number), $lines, [], [
            $member ? "Reply to this email to answer {$member->first_name} by email, or call or text them from your own phone. Loft doesn’t send replies by text."
                : 'Loft doesn’t know whose number this is. Call or text them from your own phone to answer.',
        ], $member ? route('studio.admin.members.show', ['studio' => $studio, 'member' => $member]) : null, $member ? 'Open their member page' : null, 'text_reply');
        $email->memberEmail = $member?->email;
        $email->memberName = $member?->name;
        $email->footerLine = "You’re getting this because you run {$studio->name} on ".config('app.name').' and it texts members from this number.';

        $staff = User::whereIn('id', StudioUser::where('studio_id', $studio->id)->whereIn('role', ['owner', 'admin'])->select('user_id'))->get();
        foreach ($staff as $person) {
            $messages->email($person, clone $email, "text_reply:{$messageSid}:user:{$person->id}");
        }
    }
}
