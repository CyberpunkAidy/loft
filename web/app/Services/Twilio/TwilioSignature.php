<?php

namespace App\Services\Twilio;

/**
 * Twilio signs each webhook request with the account's Auth Token: X-Twilio-Signature is the
 * base64 HMAC-SHA1 of the full URL it called followed by each POST parameter's name and value,
 * sorted by name. A request without a matching signature didn't come from that account.
 */
final class TwilioSignature
{
    /** @param array<string, mixed> $params the POST body */
    public static function valid(string $authToken, string $url, array $params, ?string $signature): bool
    {
        if ($authToken === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($authToken, $url, $params), $signature);
    }

    /** @param array<string, mixed> $params */
    public static function sign(string $authToken, string $url, array $params): string
    {
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $name => $value) {
            foreach ((array) $value as $item) {
                $data .= $name.$item;
            }
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }
}
