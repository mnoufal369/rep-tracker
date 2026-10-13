<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\Analysis\DayAnalysis;
use App\Tracking\Analysis\Segment;
use App\Tracking\Analysis\Simplifier;
use App\Tracking\Analysis\TrackAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the API representations of a rep's days.
 */
final class DayReport
{
    public function __construct(
        private readonly DayTrackReader $reader,
    ) {}

    /**
     * Summaries for a date range. Archived days come straight from their pre-computed
     * columns (no blob decoding), days still in the pending queue are analysed live.
     *
     * @return list<array<string, mixed>>
     */
    public function days(Rep $rep, string $from, string $to): array
    {
        $days = [];

        DailyTrack::where('rep_id', $rep->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->each(function (DailyTrack $t) use ($rep, &$days) {
                $date = substr((string) $t->date, 0, 10);
                $days[$date] = [
                    'date' => $date,
                    'archived' => true,
                    'point_count' => $t->point_count,
                    'first_at' => $this->local($rep, $t->first_recorded_at->timestamp),
                    'last_at' => $this->local($rep, $t->last_recorded_at->timestamp),
                    'distance_m' => $t->distance_m,
                    'moving_seconds' => $t->moving_seconds,
                    'stop_count' => $t->stop_count,
                    'gap_count' => $t->gap_count,
                    'longest_gap_seconds' => $t->longest_gap_seconds,
                ];
            });

        $pending = DB::table('pending_archive_days')
            ->where('rep_id', $rep->id)
            ->whereBetween('date', [$from, $to])
            ->pluck('date');

        foreach ($pending as $date) {
            $date = substr((string) $date, 0, 10);
            $analysis = $this->analyze($rep, $date)[1];
            $days[$date] = ['date' => $date, 'archived' => false, ...$this->summary($rep, $analysis)];
        }

        krsort($days);

        return array_values($days);
    }

    /** @return array<string, mixed> */
    public function day(Rep $rep, string $date): array
    {
        [$points, $analysis] = $this->analyze($rep, $date);

        return [
            'rep' => $this->rep($rep),
            'date' => $date,
            'storage' => $this->storage($rep, $date),
            'summary' => $this->summary($rep, $analysis),
            'timeline' => array_map(fn (Segment $s) => $s->toApi($rep->timezone), $analysis->timeline),
        ];
    }

    /**
     * GeoJSON for map rendering: the (simplified) route per continuous stretch, stops as
     * points and coverage gaps as straight dashed connectors.
     *
     * @return array<string, mixed>
     */
    public function geoJson(Rep $rep, string $date, float $toleranceM): array
    {
        [$points, $analysis] = $this->analyze($rep, $date);
        $features = [];
        $byTs = [];
        foreach ($points as $i => $p) {
            $byTs[$p->ts] = $i;
        }

        foreach ($analysis->timeline as $i => $segment) {
            $properties = ['index' => $i, ...$segment->toApi($rep->timezone)];
            unset($properties['location'], $properties['from'], $properties['to']);

            $geometry = match ($segment->type) {
                Segment::STOP => ['type' => 'Point', 'coordinates' => [round($segment->lng, 6), round($segment->lat, 6)]],
                Segment::GAP => ['type' => 'LineString', 'coordinates' => [
                    [round($segment->lng, 6), round($segment->lat, 6)],
                    [round($segment->endLng, 6), round($segment->endLat, 6)],
                ]],
                Segment::MOVE => [
                    'type' => 'LineString',
                    'coordinates' => array_map(
                        fn (Point $p) => [round($p->lng(), 6), round($p->lat(), 6)],
                        Simplifier::simplify(
                            array_slice($points, $byTs[$segment->startTs], $byTs[$segment->endTs] - $byTs[$segment->startTs] + 1),
                            $toleranceM,
                        ),
                    ),
                ],
            };

            $features[] = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => $properties];
        }

        return [
            'type' => 'FeatureCollection',
            'properties' => [
                'rep_id' => $rep->id,
                'date' => $date,
                'point_count' => count($points),
                'simplify_tolerance_m' => $toleranceM,
                'bounds' => $analysis->bounds ? [
                    [$analysis->bounds['min_lat_e7'] / 1e7, $analysis->bounds['min_lng_e7'] / 1e7],
                    [$analysis->bounds['max_lat_e7'] / 1e7, $analysis->bounds['max_lng_e7'] / 1e7],
                ] : null,
            ],
            'features' => $features,
        ];
    }

    /** @return list<Point> */
    public function points(Rep $rep, string $date): array
    {
        return $this->reader->points($rep, $date);
    }

    /** @return array<string, mixed> */
    public function rep(Rep $rep): array
    {
        return $rep->only(['id', 'name', 'email', 'timezone']);
    }

    /** @return array{0: list<Point>, 1: DayAnalysis} */
    private function analyze(Rep $rep, string $date): array
    {
        $points = $this->reader->points($rep, $date);

        return [$points, TrackAnalyzer::fromConfig()->analyze($points)];
    }

    /** @return array<string, mixed> */
    private function summary(Rep $rep, DayAnalysis $a): array
    {
        return [
            'point_count' => $a->pointCount,
            'first_at' => $a->firstTs ? $this->local($rep, $a->firstTs) : null,
            'last_at' => $a->lastTs ? $this->local($rep, $a->lastTs) : null,
            'distance_m' => $a->distanceM,
            'moving_seconds' => $a->movingSeconds,
            'stopped_seconds' => $a->stoppedSeconds,
            'stop_count' => $a->stopCount,
            'gap_count' => $a->gapCount,
            'longest_gap_seconds' => $a->longestGapSeconds,
        ];
    }

    /** @return array<string, mixed> */
    private function storage(Rep $rep, string $date): array
    {
        [$from, $to] = $rep->dayBounds($date);
        $archive = DailyTrack::where('rep_id', $rep->id)->where('date', $date)
            ->first(['point_count', 'codec_version', 'checksum', DB::raw('LENGTH(points_blob) AS blob_bytes')]);

        return [
            'archived' => $archive !== null,
            'pending_archive' => DB::table('pending_archive_days')->where(['rep_id' => $rep->id, 'date' => $date])->exists(),
            'archive_points' => $archive?->point_count,
            'archive_bytes' => $archive ? (int) $archive->blob_bytes : null,
            'hot_points' => DB::table('location_points')->where('rep_id', $rep->id)
                ->where('recorded_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('recorded_at', '<', $to->format('Y-m-d H:i:s'))
                ->count(),
        ];
    }

    private function local(Rep $rep, int $ts): string
    {
        return CarbonImmutable::createFromTimestamp($ts, $rep->timezone)->toAtomString();
    }
}
