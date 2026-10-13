<?php

namespace App\Tracking;

/**
 * The key numbers of a day: when the rep started and stopped, how far they walked,
 * and where the coverage has gaps (no GPS point for more than `tracking.gap_seconds`).
 */
final class DaySummary
{
    // Movements smaller than this are GPS noise while standing still, not walking.
    private const MIN_STEP_M = 10;

    /** @param list<Point> $points sorted by time */
    public static function of(array $points): array
    {
        $gaps = [];
        $distance = 0.0;
        $lastCounted = null;
        $previous = null;

        foreach ($points as $point) {
            if ($previous && $point->ts - $previous->ts > config('tracking.gap_seconds')) {
                $gaps[] = [
                    'from' => self::time($previous->ts),
                    'to' => self::time($point->ts),
                    'duration_seconds' => $point->ts - $previous->ts,
                ];
            }
            $previous = $point;

            if ($lastCounted) {
                $step = Geo::distance($lastCounted->lat(), $lastCounted->lng(), $point->lat(), $point->lng());
                if ($step < self::MIN_STEP_M) {
                    continue;
                }
                $distance += $step;
            }
            $lastCounted = $point;
        }

        return [
            'point_count' => count($points),
            'first_at' => $points ? self::time($points[0]->ts) : null,
            'last_at' => $points ? self::time(end($points)->ts) : null,
            'distance_m' => (int) round($distance),
            'gap_count' => count($gaps),
            'gaps' => $gaps,
        ];
    }

    private static function time(int $ts): string
    {
        return (new \DateTimeImmutable("@{$ts}"))
            ->setTimezone(new \DateTimeZone(config('tracking.timezone')))
            ->format(DATE_ATOM);
    }
}
