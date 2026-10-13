<?php

declare(strict_types=1);

namespace App\Tracking\Analysis;

use App\Tracking\Point;

/**
 * Douglas-Peucker line simplification, used only for drawing (the stored data is never
 * simplified). Coordinates are projected to local metres so the tolerance is in metres.
 */
final class Simplifier
{
    /**
     * @param  list<Point>  $points
     * @return list<Point>
     */
    public static function simplify(array $points, float $toleranceM): array
    {
        $n = count($points);
        if ($n < 3 || $toleranceM <= 0) {
            return $points;
        }

        $refLat = deg2rad($points[0]->lat());
        $xy = array_map(fn (Point $p) => [
            $p->lng() * 111_320.0 * cos($refLat),
            $p->lat() * 110_540.0,
        ], $points);

        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];

        while ($stack) {
            [$first, $last] = array_pop($stack);
            $maxDist = 0.0;
            $index = 0;
            for ($i = $first + 1; $i < $last; $i++) {
                $d = self::segmentDistance($xy[$i], $xy[$first], $xy[$last]);
                if ($d > $maxDist) {
                    $maxDist = $d;
                    $index = $i;
                }
            }
            if ($maxDist > $toleranceM) {
                $keep[$index] = true;
                $stack[] = [$first, $index];
                $stack[] = [$index, $last];
            }
        }

        return array_values(array_filter($points, fn ($_, $i) => $keep[$i], ARRAY_FILTER_USE_BOTH));
    }

    /** @param array{0: float, 1: float} $p */
    private static function segmentDistance(array $p, array $a, array $b): float
    {
        [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
        $lenSq = $dx * $dx + $dy * $dy;
        $t = $lenSq > 0 ? max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $lenSq)) : 0;

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }
}
