<?php

declare(strict_types=1);

namespace App\Tracking\Analysis;

use App\Tracking\Point;

/**
 * One entry of a day's timeline: the rep was moving, stopped somewhere, or there is no data.
 */
final readonly class Segment
{
    public const MOVE = 'move';

    public const STOP = 'stop';

    public const GAP = 'gap';

    public function __construct(
        public string $type,
        public int $startTs,
        public int $endTs,
        public int $distanceM,
        public int $pointCount,
        public float $lat,     // stop: centroid; move/gap: start position
        public float $lng,
        public ?float $endLat = null,
        public ?float $endLng = null,
        public ?float $avgSpeedMs = null,
    ) {}

    public function duration(): int
    {
        return $this->endTs - $this->startTs;
    }

    /** @return array<string, mixed> */
    public function toApi(string $timezone): array
    {
        $local = fn (int $ts) => (new \DateTimeImmutable("@{$ts}"))->setTimezone(new \DateTimeZone($timezone))->format(DATE_ATOM);

        $data = [
            'type' => $this->type,
            'start' => $local($this->startTs),
            'end' => $local($this->endTs),
            'duration_seconds' => $this->duration(),
            'point_count' => $this->pointCount,
        ];

        return $data + match ($this->type) {
            self::STOP => ['location' => ['lat' => round($this->lat, 6), 'lng' => round($this->lng, 6)]],
            default => [
                'distance_m' => $this->distanceM,
                'avg_speed_kmh' => $this->avgSpeedMs === null ? null : round($this->avgSpeedMs * 3.6, 1),
                'from' => ['lat' => round($this->lat, 6), 'lng' => round($this->lng, 6)],
                'to' => ['lat' => round($this->endLat, 6), 'lng' => round($this->endLng, 6)],
            ],
        };
    }

    /** @param list<Point> $points */
    public static function stop(array $points): self
    {
        $n = count($points);

        return new self(
            self::STOP, $points[0]->ts, $points[$n - 1]->ts, 0, $n,
            array_sum(array_map(fn (Point $p) => $p->lat(), $points)) / $n,
            array_sum(array_map(fn (Point $p) => $p->lng(), $points)) / $n,
        );
    }
}
