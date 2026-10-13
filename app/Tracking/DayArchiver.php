<?php

namespace App\Tracking;

use App\Models\DailyTrack;
use App\Models\Rep;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves a rep's day into the archive (`daily_tracks`).
 *
 * The archive is built from everything we have for that day (old archive + raw points),
 * then unpacked again and compared with the original. Only when they match is the day
 * removed from the pending list, and only then may its raw points be deleted later.
 */
final class DayArchiver
{
    public function __construct(
        private readonly DayTrackReader $reader,
    ) {}

    public function archive(Rep $rep, string $date): void
    {
        DB::transaction(function () use ($rep, $date) {
            $points = $this->reader->points($rep, $date);

            if ($points) {
                $blob = TrackCodec::encode($points);
                $checksum = TrackCodec::checksum($points);

                if (TrackCodec::checksum(TrackCodec::decode($blob)) !== $checksum) {
                    throw new RuntimeException("Archive check failed for rep {$rep->id} on {$date}.");
                }

                $summary = DaySummary::of($points);

                DailyTrack::updateOrCreate(['rep_id' => $rep->id, 'date' => $date], [
                    'point_count' => $summary['point_count'],
                    'first_recorded_at' => gmdate('Y-m-d H:i:s', $points[0]->ts),
                    'last_recorded_at' => gmdate('Y-m-d H:i:s', end($points)->ts),
                    'distance_m' => $summary['distance_m'],
                    'gap_count' => $summary['gap_count'],
                    'checksum' => $checksum,
                    'points_blob' => $blob,
                ]);
            }

            DB::table('pending_archive_days')->where(['rep_id' => $rep->id, 'date' => $date])->delete();
        });
    }

    /**
     * Archives every pending day before $beforeDate (default: today, so finished days only).
     *
     * @return int number of days archived
     */
    public function archivePending(?string $beforeDate = null): int
    {
        $pending = DB::table('pending_archive_days')
            ->where('date', '<', $beforeDate ?? LocalDay::today())
            ->get();

        foreach ($pending as $row) {
            $this->archive(Rep::findOrFail($row->rep_id), substr($row->date, 0, 10));
        }

        return $pending->count();
    }
}
