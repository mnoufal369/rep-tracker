<?php

namespace App\Tracking;

use Carbon\CarbonImmutable;

/**
 * A "day" is a calendar day in the reps' timezone, while the database stores UTC.
 * These helpers convert between the two.
 */
final class LocalDay
{
    /** The local date (Y-m-d) a timestamp falls on. */
    public static function of(int $ts): string
    {
        return CarbonImmutable::createFromTimestamp($ts, config('tracking.timezone'))->toDateString();
    }

    /** First second of the day and first second of the next day, as UTC 'Y-m-d H:i:s'. */
    public static function utcRange(string $date): array
    {
        $start = CarbonImmutable::parse($date, config('tracking.timezone'))->startOfDay();

        return [
            $start->utc()->format('Y-m-d H:i:s'),
            $start->addDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    public static function today(): string
    {
        return CarbonImmutable::now(config('tracking.timezone'))->toDateString();
    }
}
