<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\Analysis\TrackAnalyzer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compacts one rep-day into a single `daily_tracks` row.
 *
 * The archive is built from the union of the existing archive and the raw rows, then decoded
 * again and compared (checksum) with its source before it is written. Only after that does
 * the day leave the pending queue, which is what allows its raw rows to be pruned later.
 */
final class DayArchiver
{
    public function __construct(
        private readonly DayTrackReader $reader,
    ) {}

    public function archive(Rep $rep, string $date): ?DailyTrack
    {
        return DB::transaction(function () use ($rep, $date) {
            $points = $this->reader->points($rep, $date);

            if (! $points) {
                DB::table('pending_archive_days')->where(['rep_id' => $rep->id, 'date' => $date])->delete();

                return null;
            }

            $blob = TrackCodec::encode($points);
            $checksum = TrackCodec::checksum($points);
            if (TrackCodec::checksum(TrackCodec::decode($blob)) !== $checksum) {
                throw new RuntimeException("Archive verification failed for rep {$rep->id} on {$date}.");
            }

            $analysis = TrackAnalyzer::fromConfig()->analyze($points);

            $track = DailyTrack::updateOrCreate(
                ['rep_id' => $rep->id, 'date' => $date],
                [
                    'point_count' => $analysis->pointCount,
                    'first_recorded_at' => gmdate('Y-m-d H:i:s', $analysis->firstTs),
                    'last_recorded_at' => gmdate('Y-m-d H:i:s', $analysis->lastTs),
                    'distance_m' => $analysis->distanceM,
                    'moving_seconds' => $analysis->movingSeconds,
                    'stop_count' => $analysis->stopCount,
                    'gap_count' => $analysis->gapCount,
                    'longest_gap_seconds' => $analysis->longestGapSeconds,
                    ...$analysis->bounds,
                    'codec_version' => TrackCodec::VERSION,
                    'checksum' => $checksum,
                    'points_blob' => $blob,
                ],
            );

            DB::table('pending_archive_days')->where(['rep_id' => $rep->id, 'date' => $date])->delete();

            return $track;
        });
    }

    /**
     * Archives every queued day that is past the grace period.
     *
     * @return int number of days archived
     */
    public function archivePending(?callable $onDay = null): int
    {
        $cutoff = now()->subHours(config('tracking.archive_grace_hours'));
        $reps = Rep::all()->keyBy('id');
        $count = 0;

        // The queue only holds days that received data and are not archived yet: it stays small.
        $pending = DB::table('pending_archive_days')->orderBy('date')->get();

        foreach ($pending as $row) {
            $date = substr((string) $row->date, 0, 10);
            $rep = $reps->get($row->rep_id);

            if (! $rep) {
                DB::table('pending_archive_days')->where('rep_id', $row->rep_id)->delete();

                continue;
            }
            // Wait until the local day (plus grace) is over, so late uploads land first.
            if ($rep->dayBounds($date)[1]->greaterThan($cutoff)) {
                continue;
            }

            $this->archive($rep, $date);
            $count++;
            $onDay && $onDay($rep, $date);
        }

        return $count;
    }
}
