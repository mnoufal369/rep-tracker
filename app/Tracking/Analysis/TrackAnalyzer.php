<?php

declare(strict_types=1);

namespace App\Tracking\Analysis;

use App\Tracking\Geo;
use App\Tracking\Point;

/**
 * Turns a day of raw fixes into a timeline of moves, stops and coverage gaps.
 *
 *  1. The day is cut into continuous chunks wherever two fixes are more than `gap_seconds`
 *     apart; each cut becomes a GAP entry (the device recorded nothing in that window).
 *  2. Inside a chunk, a STOP is a run of fixes that all stay within `stop_radius_m` of the
 *     first one for at least `stop_min_seconds` (a customer visit, lunch, ...).
 *  3. Everything between stops is a MOVE. Distance is only counted on moves, so GPS jitter
 *     while standing still does not inflate the walked distance.
 */
final class TrackAnalyzer
{
    private const MAX_ACCURACY_FOR_DISTANCE_M = 50;

    private const DISTANCE_SMOOTHING_M = 8;

    private const MAX_OUTLIERS = 2;

    public function __construct(
        private readonly int $stopRadiusM = 50,
        private readonly int $stopMinSeconds = 180,
        private readonly int $gapSeconds = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('tracking.timeline.stop_radius_m'),
            config('tracking.timeline.stop_min_seconds'),
            config('tracking.timeline.gap_seconds'),
        );
    }

    /** @param list<Point> $points sorted by time */
    public function analyze(array $points): DayAnalysis
    {
        $timeline = [];
        $previousChunkEnd = null;

        foreach ($this->splitOnGaps($points) as $chunk) {
            if ($previousChunkEnd !== null) {
                $timeline[] = new Segment(
                    Segment::GAP, $previousChunkEnd->ts, $chunk[0]->ts,
                    (int) round(Geo::distance($previousChunkEnd, $chunk[0])), 0,
                    $previousChunkEnd->lat(), $previousChunkEnd->lng(), $chunk[0]->lat(), $chunk[0]->lng(),
                );
            }
            array_push($timeline, ...$this->segmentChunk($chunk));
            $previousChunkEnd = $chunk[count($chunk) - 1];
        }

        $sum = fn (string $type, callable $fn) => array_sum(array_map($fn, array_filter($timeline, fn (Segment $s) => $s->type === $type)));
        $gaps = array_filter($timeline, fn (Segment $s) => $s->type === Segment::GAP);

        return new DayAnalysis(
            timeline: $timeline,
            pointCount: count($points),
            firstTs: $points[0]->ts ?? null,
            lastTs: $points ? $points[count($points) - 1]->ts : null,
            distanceM: $sum(Segment::MOVE, fn (Segment $s) => $s->distanceM),
            movingSeconds: $sum(Segment::MOVE, fn (Segment $s) => $s->duration()),
            stoppedSeconds: $sum(Segment::STOP, fn (Segment $s) => $s->duration()),
            stopCount: count(array_filter($timeline, fn (Segment $s) => $s->type === Segment::STOP)),
            gapCount: count($gaps),
            longestGapSeconds: $gaps ? max(array_map(fn (Segment $s) => $s->duration(), $gaps)) : 0,
            bounds: $this->bounds($points),
        );
    }

    /**
     * @param  list<Point>  $points
     * @return list<list<Point>>
     */
    private function splitOnGaps(array $points): array
    {
        $chunks = [];
        $current = [];
        foreach ($points as $p) {
            if ($current && $p->ts - $current[count($current) - 1]->ts > $this->gapSeconds) {
                $chunks[] = $current;
                $current = [];
            }
            $current[] = $p;
        }
        if ($current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  list<Point>  $chunk
     * @return list<Segment>
     */
    private function segmentChunk(array $chunk): array
    {
        $n = count($chunk);
        $stops = [];   // list of [startIndex, endIndex]
        $i = 0;
        while ($i < $n) {
            $j = $this->stayEnd($chunk, $i);
            if ($chunk[$j]->ts - $chunk[$i]->ts >= $this->stopMinSeconds) {
                $stops[] = [$i, $j];
                $i = $j + 1;
            } else {
                $i++;
            }
        }
        $stops = $this->mergeAdjacentStops($chunk, $stops);

        $segments = [];
        $cursor = 0;          // first index not yet covered by a stop
        $previousStop = null;
        foreach ($stops as [$start, $end]) {
            $stop = Segment::stop(array_slice($chunk, $start, $end - $start + 1));
            if ($start > $cursor) {
                // The move runs from the last fix of the previous stop to the first of this one.
                $from = max(0, $cursor - 1);
                $segments[] = $this->move(array_slice($chunk, $from, $start - $from + 1), $previousStop, $stop);
            }
            $segments[] = $stop;
            $previousStop = $stop;
            $cursor = $end + 1;
        }
        if ($cursor < $n) {
            $segments[] = $this->move(array_slice($chunk, max(0, $cursor - 1)), $previousStop, null);
        }

        // A lone fix between two stops (or at the very start) is not a meaningful move.
        return array_values(array_filter($segments, fn (Segment $s) => $s->type !== Segment::MOVE || $s->duration() > 0));
    }

    /**
     * Index of the last fix that still belongs to a stay anchored at $i: fixes within the stop
     * radius of the anchor, tolerating up to two consecutive outliers (indoor GPS can jump
     * 50+ m for a single fix) as long as the track comes back.
     *
     * @param  list<Point>  $chunk
     */
    private function stayEnd(array $chunk, int $i): int
    {
        $n = count($chunk);
        $last = $i;
        for ($j = $i + 1; $j < $n; $j++) {
            if (Geo::distance($chunk[$i], $chunk[$j]) <= $this->stopRadiusM) {
                $last = $j;
            } elseif ($j - $last > self::MAX_OUTLIERS) {
                break;
            }
        }

        return $last;
    }

    /**
     * Two stops separated by a single outlier fix (or a few steps inside the same building)
     * are one visit.
     *
     * @param  list<Point>  $chunk
     * @param  list<array{0: int, 1: int}>  $stops
     * @return list<array{0: int, 1: int}>
     */
    private function mergeAdjacentStops(array $chunk, array $stops): array
    {
        $merged = [];
        foreach ($stops as $stop) {
            $previous = $merged ? $merged[count($merged) - 1] : null;
            if ($previous
                && $chunk[$stop[0]]->ts - $chunk[$previous[1]]->ts <= 120
                && $this->centroidDistance($chunk, $previous, $stop) <= $this->stopRadiusM) {
                $merged[count($merged) - 1] = [$previous[0], $stop[1]];

                continue;
            }
            $merged[] = $stop;
        }

        return $merged;
    }

    /** @param list<Point> $chunk */
    private function centroidDistance(array $chunk, array $a, array $b): float
    {
        $sa = Segment::stop(array_slice($chunk, $a[0], $a[1] - $a[0] + 1));
        $sb = Segment::stop(array_slice($chunk, $b[0], $b[1] - $b[0] + 1));

        return Geo::haversine($sa->lat, $sa->lng, $sb->lat, $sb->lng);
    }

    /**
     * Distance is measured from the centre of the previous stop to the centre of the next one
     * (the edges of a stop also contain a few metres of walking), on a lightly simplified
     * path: summing raw fixes would add the GPS noise (metres of zig-zag per fix) to it.
     *
     * @param  list<Point>  $points
     */
    private function move(array $points, ?Segment $fromStop, ?Segment $toStop): Segment
    {
        $path = array_values(array_filter($points, fn (Point $p) => $p->accuracyM === null || $p->accuracyM <= self::MAX_ACCURACY_FOR_DISTANCE_M));
        $path = Simplifier::simplify($path, self::DISTANCE_SMOOTHING_M);
        if ($fromStop) {
            array_unshift($path, $this->centroid($fromStop));
        }
        if ($toStop) {
            $path[] = $this->centroid($toStop);
        }

        $distance = 0.0;
        for ($k = 1, $count = count($path); $k < $count; $k++) {
            $distance += Geo::distance($path[$k - 1], $path[$k]);
        }
        $first = $points[0];
        $end = $points[count($points) - 1];

        // Prefer the speed the phone measured (Doppler-based, precise); fall back to distance / time.
        $speeds = array_filter(array_map(fn (Point $p) => $p->speedCms, $points), fn (?int $v) => $v > 0);
        $duration = $end->ts - $first->ts;
        $avgSpeed = $speeds ? array_sum($speeds) / count($speeds) / 100 : ($duration > 0 ? $distance / $duration : null);

        return new Segment(
            Segment::MOVE, $first->ts, $end->ts, (int) round($distance), count($points),
            $first->lat(), $first->lng(), $end->lat(), $end->lng(), $avgSpeed,
        );
    }

    private function centroid(Segment $stop): Point
    {
        return new Point($stop->startTs, (int) round($stop->lat * 1e7), (int) round($stop->lng * 1e7));
    }

    /** @param list<Point> $points */
    private function bounds(array $points): ?array
    {
        if (! $points) {
            return null;
        }
        $lats = array_map(fn (Point $p) => $p->latE7, $points);
        $lngs = array_map(fn (Point $p) => $p->lngE7, $points);

        return [
            'min_lat_e7' => min($lats), 'min_lng_e7' => min($lngs),
            'max_lat_e7' => max($lats), 'max_lng_e7' => max($lngs),
        ];
    }
}
