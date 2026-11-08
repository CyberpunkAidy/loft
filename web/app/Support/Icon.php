<?php

namespace App\Support;

use InvalidArgumentException;

/** Inline SVG icons from resources/icons (a subset of Lucide, ISC license). Use <x-icon name="…" />. */
final class Icon
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function svg(string $name, string $class = ''): string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new InvalidArgumentException("Bad icon name [{$name}].");
        }
        $svg = self::$cache[$name] ??= trim((string) file_get_contents(resource_path("icons/{$name}.svg")));

        return (string) preg_replace('/^<svg /', '<svg class="'.e(trim("icon {$class}")).'" aria-hidden="true" focusable="false" ', $svg, 1);
    }
}
