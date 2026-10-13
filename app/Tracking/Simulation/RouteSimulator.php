<?php

declare(strict_types=1);

namespace App\Tracking\Simulation;

use App\Models\Rep;
use App\Tracking\Geo;
use App\Tracking\Point;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generates a realistic, deterministic (seeded) shift for a door-to-door rep.
 *
 * The rep walks a street-grid pattern through one Amsterdam district: blocks of 60-200 m at
 * walking speed, turning at "intersections", stopping at doors for customer visits, with a
 * lunch break. The simulated phone behaves like a real tracking app: a fix every ~5 s while
 * moving, a heartbeat every 60 s while stationary, GPS noise that is worse indoors, a
 * draining battery, and optionally a stretch with no GPS fix at all (e.g. inside a building).
 */
final class RouteSimulator
{
    /** name => [lat, lng, street grid rotation in degrees] */
    public const DISTRICTS = [
        'Jordaan' => [52.3745, 4.8810, 28],
        'De Pijp' => [52.3545, 4.8945, 12],
        'Oud-West' => [52.3655, 4.8675, 22],
        'Oost' => [52.3610, 4.9245, 35],
        'Noord' => [52.3885, 4.9185, 5],
        'Zuid' => [52.3505, 4.8725, 0],
    ];

    private const MAX_RADIUS_M = 900;

    private Randomizer $rng;

    /** @var list<Point> */
    private array $points = [];

    private float $lat;

    private float $lng;

    private int $t;

    private int $battery = 100;

    public function __construct(
        private readonly bool $withSignalLoss = true,
    ) {}

    /**
     * @return array{district: string, points: list<Point>}
     */
    public function generate(Rep $rep, string $date, ?int $seed = null): array
    {
        $this->rng = new Randomizer(new Mt19937($seed ?? crc32("{$rep->id}|{$date}")));
        $this->points = [];
        $this->battery = $this->int(85, 100);

        $district = array_keys(self::DISTRICTS)[($rep->id + $this->int(0, 2)) % count(self::DISTRICTS)];
        [$centerLat, $centerLng, $gridRotation] = self::DISTRICTS[$district];

        $day = CarbonImmutable::parse($date, $rep->timezone)->startOfDay();
        $this->t = $day->setTime(8, 45)->addMinutes($this->int(0, 30))->timestamp;
        $shiftEnd = $day->setTime(17, 0)->addMinutes($this->int(0, 45))->timestamp;
        $lunchAt = $day->setTime(12, 15)->addMinutes($this->int(0, 45))->timestamp;
        $hadLunch = false;
        $signalLossAt = $this->withSignalLoss && $this->chance(0.5)
            ? $this->int($this->t + 3600, $shiftEnd - 3600) : null;

        [$this->lat, $this->lng] = Geo::offset($centerLat, $centerLng, $this->float(0, 360), $this->float(100, 500));
        $heading = $gridRotation + 90 * $this->int(0, 3);

        while ($this->t < $shiftEnd) {
            // Walk a few blocks, turning at intersections and staying inside the district.
            for ($blocks = $this->int(1, 3); $blocks > 0; $blocks--) {
                if (Geo::haversine($this->lat, $this->lng, $centerLat, $centerLng) > self::MAX_RADIUS_M) {
                    $heading = $this->gridHeadingTowards($centerLat, $centerLng, $gridRotation);
                } elseif ($this->chance(0.5)) {
                    $heading += $this->chance(0.5) ? 90 : -90;
                }
                $this->walk($heading, $this->float(40, 160), $signalLossAt);
            }

            if (! $hadLunch && $this->t >= $lunchAt) {
                $this->stay($this->int(30, 45) * 60, indoor: true);
                $hadLunch = true;

                continue;
            }

            // Knock on a door: often nobody answers, sometimes a proper sales conversation.
            $this->stay($this->chance(0.45) ? $this->int(30, 120) : $this->int(4, 20) * 60, indoor: $this->chance(0.6));
        }

        return ['district' => $district, 'points' => $this->points];
    }

    private function walk(float $heading, float $distanceM, ?int &$signalLossAt): void
    {
        $speed = $this->float(1.1, 1.6);
        $walked = 0.0;
        while ($walked < $distanceM) {
            $dt = $this->int(4, 6);
            $step = min($speed * $dt, $distanceM - $walked);
            [$this->lat, $this->lng] = Geo::offset($this->lat, $this->lng, $heading + $this->float(-4, 4), $step);
            $walked += $step;
            $this->t += $dt;

            if ($signalLossAt !== null && $this->t >= $signalLossAt) {
                // No GPS fix at all for 6-15 minutes, while the rep keeps walking.
                $lostFor = $this->int(6, 15) * 60;
                [$this->lat, $this->lng] = Geo::offset($this->lat, $this->lng, $heading, $speed * $lostFor * 0.6);
                $this->t += $lostFor;
                $signalLossAt = null;
            }

            $this->record(accuracy: $this->int(4, 12), speedMs: $speed + $this->float(-0.2, 0.2), heading: $heading);
        }
    }

    private function stay(int $seconds, bool $indoor): void
    {
        $until = $this->t + $seconds;
        while ($this->t < $until) {
            $this->t += min(60, max(1, $until - $this->t));
            $this->record(accuracy: $indoor ? $this->int(15, 35) : $this->int(5, 12), speedMs: 0, heading: null);
        }
    }

    private function record(int $accuracy, float $speedMs, ?float $heading): void
    {
        // Gaussian GPS noise (Box-Muller) with sigma ~ half the reported accuracy.
        $sigma = $accuracy / 2;
        $r = sqrt(-2 * log(max(1e-12, $this->float(0, 1)))) * $sigma;
        [$lat, $lng] = Geo::offset($this->lat, $this->lng, $this->float(0, 360), $r);

        if ($this->chance(0.004)) {
            $this->battery = max(5, $this->battery - 1);
        }

        $this->points[] = new Point(
            ts: $this->t,
            latE7: (int) round($lat * 1e7),
            lngE7: (int) round($lng * 1e7),
            accuracyM: $accuracy,
            speedCms: (int) round(max(0, $speedMs) * 100),
            headingDeg: $heading === null ? null : ((int) round($heading) % 360 + 360) % 360,
            batteryPct: $this->battery,
        );
    }

    private function gridHeadingTowards(float $lat, float $lng, float $gridRotation): float
    {
        $bearing = Geo::bearing($this->lat, $this->lng, $lat, $lng);
        $best = $gridRotation;
        foreach ([0, 90, 180, 270] as $k) {
            $candidate = $gridRotation + $k;
            if (abs(fmod($candidate - $bearing + 540, 360) - 180) < abs(fmod($best - $bearing + 540, 360) - 180)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    private function float(float $min, float $max): float
    {
        return $this->rng->getFloat($min, $max);
    }

    private function chance(float $p): bool
    {
        return $this->rng->getFloat(0, 1) < $p;
    }
}
