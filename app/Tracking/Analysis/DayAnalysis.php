<?php

declare(strict_types=1);

namespace App\Tracking\Analysis;

final readonly class DayAnalysis
{
    /** @param list<Segment> $timeline */
    public function __construct(
        public array $timeline,
        public int $pointCount,
        public ?int $firstTs,
        public ?int $lastTs,
        public int $distanceM,
        public int $movingSeconds,
        public int $stoppedSeconds,
        public int $stopCount,
        public int $gapCount,
        public int $longestGapSeconds,
        /** @var array{min_lat_e7: int, min_lng_e7: int, max_lat_e7: int, max_lng_e7: int}|null */
        public ?array $bounds,
    ) {}
}
