<?php

namespace App\Tracking;

final class Geo
{
    private const EARTH_RADIUS_M = 6371000;

    /** Distance in metres between two points (haversine formula). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(sqrt($a));
    }

    /** Moves a point $meters in the direction $bearing (0 = north, 90 = east). */
    public static function move(float $lat, float $lng, float $bearing, float $meters): array
    {
        $dLat = $meters * cos(deg2rad($bearing)) / self::EARTH_RADIUS_M;
        $dLng = $meters * sin(deg2rad($bearing)) / (self::EARTH_RADIUS_M * cos(deg2rad($lat)));

        return [$lat + rad2deg($dLat), $lng + rad2deg($dLng)];
    }
}
