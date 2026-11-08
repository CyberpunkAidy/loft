<?php

namespace App\Support;

/**
 * Readable colors to pair with a studio's brand color, using WCAG relative luminance and contrast.
 * Studios pick any color, so text on it, and the color used as text, are worked out here.
 */
final class Color
{
    public const INK = '#17141f';

    public static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    public static function contrast(string $a, string $b): float
    {
        [$la, $lb] = [self::luminance($a), self::luminance($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or near-black: whichever reads better on $hex (button labels on the brand color). */
    public static function inkOn(string $hex): string
    {
        return self::contrast($hex, '#ffffff') >= self::contrast($hex, self::INK) ? '#ffffff' : self::INK;
    }

    /** $hex, darkened just enough to read as text on white (4.5:1), for links and labels. */
    public static function textOnWhite(string $hex): string
    {
        $out = strtolower($hex);
        for ($t = 0.05; self::contrast($out, '#ffffff') < 4.5 && $t <= 1; $t += 0.05) {
            $out = self::mix($hex, '#000000', $t);
        }

        return $out;
    }

    public static function mix(string $a, string $b, float $t): string
    {
        [$x, $y] = [self::rgb($a), self::rgb($b)];

        return vsprintf('#%02x%02x%02x', array_map(fn (int $i) => (int) round($x[$i] + ($y[$i] - $x[$i]) * $t), [0, 1, 2]));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
