<?php

namespace App\Http\Resources\Api;

use App\Models\Studio;
use DateTimeInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** The API's resources: times in UTC ("2026-11-10T01:00:00Z"), plus the studio's local time where it helps. */
abstract class ApiResource extends JsonResource
{
    protected static function utc(?DateTimeInterface $at): ?string
    {
        return $at ? Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** The same moment in the studio's time zone, with its offset ("2026-11-09T18:00:00-07:00"). */
    protected static function local(Studio $studio, ?DateTimeInterface $at): ?string
    {
        return $at ? $studio->local(Carbon::instance($at))->toIso8601String() : null;
    }
}
