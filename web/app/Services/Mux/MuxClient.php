<?php

namespace App\Services\Mux;

use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mux Video, on Loft's own account (decision 33). Every asset is created with a signed playback
 * policy and basic video quality (no encoding charge); the tokens that play it come from token().
 * Mux's docs: https://www.mux.com/docs/api-reference/video and /docs/guides/secure-video-playback.
 */
class MuxClient
{
    public const API = 'https://api.mux.com/video/v1/';

    /** How long a direct upload URL waits for the file. */
    public const UPLOAD_TIMEOUT_SECONDS = 6 * 3600;

    public function __construct(private readonly array $config = []) {}

    public static function fromConfig(): self
    {
        return new self((array) config('loft.mux'));
    }

    /** API keys set: videos can be uploaded and managed. */
    public function configured(): bool
    {
        return filled($this->config['token_id'] ?? null) && filled($this->config['token_secret'] ?? null);
    }

    /** A signing key set: videos can be played. */
    public function canSign(): bool
    {
        return filled($this->config['signing_key_id'] ?? null) && filled($this->config['signing_key'] ?? null);
    }

    /**
     * A direct upload: the browser PUTs the file to the returned url.
     *
     * @return array{id: string, url: string}
     */
    public function createUpload(Video $video, string $corsOrigin): array
    {
        $data = $this->send('POST', 'uploads', [
            'cors_origin' => $corsOrigin,
            'timeout' => self::UPLOAD_TIMEOUT_SECONDS,
            'new_asset_settings' => $this->assetSettings($video),
        ]);

        return ['id' => (string) $data['id'], 'url' => (string) $data['url']];
    }

    /** An asset from a file Mux fetches itself (a public link to an .mp4, a Dropbox link…). */
    public function createAsset(Video $video, string $url): array
    {
        return $this->send('POST', 'assets', ['inputs' => [['url' => $url]]] + $this->assetSettings($video));
    }

    /** @return array{id: string, status: string, asset_id?: string, error?: array} status: waiting, asset_created, errored, cancelled, timed_out */
    public function upload(string $id): array
    {
        return $this->send('GET', 'uploads/'.rawurlencode($id));
    }

    /** @return array{id: string, status: string, duration?: float, aspect_ratio?: string, playback_ids?: list<array{id: string, policy: string}>, errors?: array} status: preparing, ready, errored */
    public function asset(string $id): array
    {
        return $this->send('GET', 'assets/'.rawurlencode($id));
    }

    /** Deletes the asset and its playback IDs. Already gone is fine. */
    public function deleteAsset(string $id): void
    {
        $this->send('DELETE', 'assets/'.rawurlencode($id), allowMissing: true);
    }

    /** Stops an upload that hasn't finished. Already finished or gone is fine. */
    public function cancelUpload(string $id): void
    {
        $this->send('PUT', 'uploads/'.rawurlencode($id).'/cancel', allowMissing: true, allowConflict: true);
    }

    /**
     * A signed playback token (RS256 JWT) that works until $expiresAt (unix time): $audience is v
     * (video), t (thumbnail) or s (storyboard). For thumbnails, image options (width, time…) must be
     * $claims, not the query string. The same inputs give the same token, so browsers can cache.
     */
    public function token(string $playbackId, string $audience, int $expiresAt, array $claims = []): string
    {
        if (! $this->canSign()) {
            throw new MuxException('Mux signing isn’t configured. Set MUX_SIGNING_KEY_ID and MUX_SIGNING_KEY.');
        }
        $kid = (string) $this->config['signing_key_id'];
        $segments = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]))
            .'.'.self::base64Url(json_encode(['sub' => $playbackId, 'aud' => $audience, 'exp' => $expiresAt, 'kid' => $kid] + $claims));
        $key = openssl_pkey_get_private($this->privateKey());
        if ($key === false || ! openssl_sign($segments, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new MuxException('The Mux signing key isn’t a valid private key.');
        }

        return $segments.'.'.self::base64Url($signature);
    }

    /** Mux shows the private key base64-encoded; a PEM pasted as-is works too. */
    private function privateKey(): string
    {
        $key = trim((string) $this->config['signing_key']);

        return str_starts_with($key, '-----BEGIN') ? str_replace('\n', "\n", $key) : (string) base64_decode($key, true);
    }

    private function assetSettings(Video $video): array
    {
        return [
            'playback_policies' => ['signed'],
            'video_quality' => 'basic',
            'passthrough' => 'loft:video:'.$video->id,
        ];
    }

    private function send(string $method, string $path, ?array $body = null, bool $allowMissing = false, bool $allowConflict = false): array
    {
        try {
            $response = $this->http()->send($method, self::API.$path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new MuxException('Couldn’t reach Mux: '.$e->getMessage());
        }
        if (($allowMissing && $response->status() === 404) || ($allowConflict && in_array($response->status(), [400, 409], true))) {
            return [];
        }

        return $this->decode($response);
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new MuxException('Mux isn’t configured. Set MUX_TOKEN_ID and MUX_TOKEN_SECRET.');
        }

        return Http::withBasicAuth((string) $this->config['token_id'], (string) $this->config['token_secret'])
            ->acceptJson()->timeout(30);
    }

    private function decode(Response $response): array
    {
        $body = $response->json() ?? [];
        if ($response->failed()) {
            $messages = $body['error']['messages'] ?? [];
            throw new MuxException(is_array($messages) && $messages ? implode(' ', $messages) : 'Mux error '.$response->status(), $response->status());
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
