<?php

declare(strict_types=1);

namespace App\Tracking;

final class Geo
{
    private const EARTH_RADIUS_M = 6371008.8;

    public static function distance(Point $a, Point $b): float
    {
        return self::haversine($a->lat(), $a->lng(), $b->lat(), $b->lng());
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * Moves a coordinate by a distance (m) along a bearing (degrees). Accurate enough for
     * the short hops of a walking route.
     *
     * @return array{0: float, 1: float}
     */
    public static function offset(float $lat, float $lng, float $bearingDeg, float $distanceM): array
    {
        $bearing = deg2rad($bearingDeg);
        $dLat = $distanceM * cos($bearing) / self::EARTH_RADIUS_M;
        $dLng = $distanceM * sin($bearing) / (self::EARTH_RADIUS_M * cos(deg2rad($lat)));

        return [$lat + rad2deg($dLat), $lng + rad2deg($dLng)];
    }

    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
