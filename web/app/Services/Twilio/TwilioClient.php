<?php

namespace App\Services\Twilio;

use App\Models\Studio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin client for Twilio's REST API (no SDK), for each studio's own Twilio account (decision 48):
 * every call is made with that studio's Account SID and Auth Token.
 */
class TwilioClient
{
    public const API = 'https://api.twilio.com/2010-04-01/';

    /** The account, to check the SID and token work: ['friendly_name' => …, 'status' => 'active', 'type' => 'Full'|'Trial']. */
    public function account(string $sid, string $token): array
    {
        return $this->call('get', $sid, $token, "Accounts/{$sid}.json");
    }

    /**
     * The account's numbers that can send texts.
     *
     * @return list<array{sid: string, phone_number: string, friendly_name: string, sms_url: ?string}>
     */
    public function smsNumbers(string $sid, string $token): array
    {
        $numbers = $this->call('get', $sid, $token, "Accounts/{$sid}/IncomingPhoneNumbers.json", ['PageSize' => 100])['incoming_phone_numbers'] ?? [];

        return array_values(array_map(fn (array $n) => [
            'sid' => (string) $n['sid'],
            'phone_number' => (string) $n['phone_number'],
            'friendly_name' => (string) ($n['friendly_name'] ?? $n['phone_number']),
            'sms_url' => $n['sms_url'] ?? null,
        ], array_filter($numbers, fn ($n) => is_array($n) && ! empty($n['capabilities']['sms']))));
    }

    /** Send texts that arrive at the number to $url (replies, STOP and START), by POST. */
    public function pointSmsAt(string $sid, string $token, string $numberSid, string $url): void
    {
        $this->call('post', $sid, $token, "Accounts/{$sid}/IncomingPhoneNumbers/{$numberSid}.json", ['SmsUrl' => $url, 'SmsMethod' => 'POST']);
    }

    /** Send a text from the studio's number. Returns Twilio's message: ['sid' => 'SM…', 'status' => 'queued', …]. */
    public function send(Studio $studio, string $to, string $body, ?string $statusCallback = null): array
    {
        return $this->call('post', (string) $studio->twilio_account_sid, (string) $studio->twilio_auth_token, "Accounts/{$studio->twilio_account_sid}/Messages.json", array_filter([
            'From' => $studio->sms_from,
            'To' => $to,
            'Body' => $body,
            'StatusCallback' => $statusCallback,
        ]));
    }

    private function call(string $method, string $sid, string $token, string $path, array $params = []): array
    {
        try {
            $response = $this->http($sid, $token)->{$method}(self::API.$path, $params);
        } catch (ConnectionException $e) {
            throw new TwilioException('Couldn’t reach Twilio: '.$e->getMessage());
        }

        return $this->decode($response);
    }

    private function http(string $sid, string $token): PendingRequest
    {
        return Http::withBasicAuth($sid, $token)->asForm()->acceptJson()->timeout(20);
    }

    private function decode(Response $response): array
    {
        $body = $response->json() ?? [];
        if ($response->failed()) {
            $code = isset($body['code']) ? (int) $body['code'] : null;
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Twilio error '.$response->status();
            throw new TwilioException($message, $code, $response->status());
        }

        return is_array($body) ? $body : [];
    }
}
