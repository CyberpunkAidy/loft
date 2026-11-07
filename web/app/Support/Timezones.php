<?php

namespace App\Support;

use DateTimeZone;

class Timezones
{
    private const COMMON = [
        'America/New_York' => 'Eastern (New York)',
        'America/Chicago' => 'Central (Chicago)',
        'America/Denver' => 'Mountain (Denver)',
        'America/Phoenix' => 'Mountain, no DST (Phoenix)',
        'America/Los_Angeles' => 'Pacific (Los Angeles)',
        'America/Anchorage' => 'Alaska (Anchorage)',
        'Pacific/Honolulu' => 'Hawaii (Honolulu)',
        'America/Toronto' => 'Eastern (Toronto)',
        'America/Vancouver' => 'Pacific (Vancouver)',
        'Europe/London' => 'UK (London)',
        'Australia/Sydney' => 'Australia (Sydney)',
        'Pacific/Auckland' => 'New Zealand (Auckland)',
    ];

    /** @return array<string, string> common zones first, then every IANA zone */
    public static function options(): array
    {
        $all = [];
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            if (! isset(self::COMMON[$zone])) {
                $all[$zone] = str_replace('_', ' ', $zone);
            }
        }

        return self::COMMON + $all;
    }
}
