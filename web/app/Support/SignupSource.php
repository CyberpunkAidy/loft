<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How a studio found Loft (decision 43): the answer to "How did you hear about Loft?" at signup,
 * and where the visit began. The first page someone opens in a session records its campaign tags
 * (utm_*), the site that linked to it and the page itself, in the session only (no cookie of its
 * own); signing up a studio saves them with it.
 */
final class SignupSource
{
    public const SESSION = 'signup_source';

    /** The answers to "How did you hear about Loft?", in the order the form shows them. */
    public const HEARD_FROM = [
        'search' => 'Searching online',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'social' => 'Another social site (TikTok, YouTube…)',
        'studio' => 'Another studio owner',
        'instructor' => 'An instructor or teacher',
        'group' => 'Reddit or an online group',
        'review' => 'A review or comparison site',
        'event' => 'An event, workshop or competition',
        'loft' => 'A message from Loft',
        'other' => 'Somewhere else',
    ];

    public const TAGS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /** Records the first page of a guest's visit, once per session. */
    public static function remember(Request $request): void
    {
        if (! $request->isMethod('GET') || ! $request->hasSession() || $request->user() || $request->session()->has(self::SESSION)) {
            return;
        }
        $source = [];
        foreach (self::TAGS as $tag) {
            $value = $request->query($tag);
            if (is_string($value) && trim($value) !== '') {
                $source[$tag] = mb_substr(trim($value), 0, 100);
            }
        }
        $from = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        if (is_string($from) && $from !== '' && strcasecmp($from, $request->getHost()) !== 0) {
            $source['referrer'] = mb_substr(strtolower($from), 0, 100);
        }
        $source['landing'] = mb_substr('/'.ltrim($request->path(), '/'), 0, 200);

        $request->session()->put(self::SESSION, $source);
    }

    /** @return array<string, string>|null what remember() recorded for this visit */
    public static function recorded(Request $request): ?array
    {
        $source = $request->hasSession() ? $request->session()->get(self::SESSION) : null;

        return is_array($source) && $source !== [] ? $source : null;
    }

    /** One line for the report: "instagram / bio (spring)", "google.com", or the landing page. */
    public static function describe(?array $source): string
    {
        if (! $source) {
            return '';
        }
        $tags = array_filter([$source['utm_source'] ?? null, $source['utm_medium'] ?? null]);
        $line = $tags ? implode(' / ', $tags).(isset($source['utm_campaign']) ? " ({$source['utm_campaign']})" : '') : ($source['referrer'] ?? '');

        return trim($line.(isset($source['landing']) && $source['landing'] !== '/' ? ' → '.$source['landing'] : ''), ' →');
    }
}
