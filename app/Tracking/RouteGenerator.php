<?php

namespace App\Tracking;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generates a believable working day for a door-to-door rep in Amsterdam.
 *
 * The rep walks for a few minutes (a GPS point every 5 seconds), visits a customer for a
 * few minutes (a point every 60 seconds while standing still), and repeats that from about
 * 09:00 until 17:00. Sometimes the GPS signal is lost for 10 minutes, which shows up as a
 * gap in the day summary. The same seed always gives the same day.
 */
final class RouteGenerator
{
    private const CITY_CENTRE = [52.3600, 4.8850];

    private const MAX_DISTANCE_FROM_CENTRE_M = 1500;

    private const WALKING_SPEED_MS = 1.3;

    private Randomizer $random;

    /** @return list<array{recorded_at: string, lat: float, lng: float, accuracy: int}> */
    public function generate(string $date, int $seed): array
    {
        $this->random = new Randomizer(new Mt19937($seed));

        $day = CarbonImmutable::parse($date, config('tracking.timezone'));
        $time = $day->setTime(9, 0)->timestamp + $this->random->getInt(0, 1800);
        $endOfShift = $day->setTime(17, 0)->timestamp;

        [$lat, $lng] = Geo::move(...self::CITY_CENTRE, bearing: $this->random->getInt(0, 359), meters: $this->random->getInt(0, 1000));
        $heading = $this->random->getInt(0, 359);
        $points = [];

        while ($time < $endOfShift) {
            // Walk for 2-10 minutes.
            for ($until = $time + $this->random->getInt(120, 600); $time < $until; $time += 5) {
                $heading = $this->tooFarFromCentre($lat, $lng) ? $this->headingToCentre($lat, $lng) : $heading + $this->random->getInt(-20, 20);
                [$lat, $lng] = Geo::move($lat, $lng, $heading, self::WALKING_SPEED_MS * 5);
                $points[] = $this->point($time, $lat, $lng, accuracy: 8);
            }

            // Visit a customer for 3-20 minutes.
            for ($until = $time + $this->random->getInt(3, 20) * 60; $time < $until; $time += 60) {
                $points[] = $this->point($time, $lat, $lng, accuracy: 20);
            }

            // Once in a while: no GPS signal for 10 minutes while walking on.
            if ($this->random->getInt(1, 40) === 1) {
                $time += 600;
                [$lat, $lng] = Geo::move($lat, $lng, $heading, 300);
            }
        }

        // When generating today, only the part of the shift that already happened.
        return array_values(array_filter($points, fn (array $p) => strtotime($p['recorded_at']) <= time()));
    }

    private function point(int $time, float $lat, float $lng, int $accuracy): array
    {
        // Real GPS is never exact: move the point a few metres in a random direction.
        [$lat, $lng] = Geo::move($lat, $lng, $this->random->getInt(0, 359), $this->random->getInt(0, intdiv($accuracy, 2)));

        return [
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', $time),
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
            'accuracy' => $accuracy,
        ];
    }

    private function tooFarFromCentre(float $lat, float $lng): bool
    {
        return Geo::distance($lat, $lng, ...self::CITY_CENTRE) > self::MAX_DISTANCE_FROM_CENTRE_M;
    }

    /** Compass direction (degrees) from here back to the city centre. */
    private function headingToCentre(float $lat, float $lng): float
    {
        [$centreLat, $centreLng] = self::CITY_CENTRE;

        return rad2deg(atan2(($centreLng - $lng) * cos(deg2rad($lat)), $centreLat - $lat));
    }
}
