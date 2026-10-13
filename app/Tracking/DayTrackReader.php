<?php

namespace App\Tracking;

use App\Models\DailyTrack;
use App\Models\Rep;
use Illuminate\Support\Facades\DB;

/**
 * Returns all points of a rep's day, wherever they are stored.
 *
 * A day can be in the raw table (recent), in the archive (old), or in both (recent and
 * already archived, or a late upload for an archived day). We read both and merge them,
 * so the caller never has to care where the data lives.
 */
final class DayTrackReader
{
    /** @return list<Point> sorted by time */
    public function points(Rep $rep, string $date): array
    {
        $byTime = [];

        $archive = DailyTrack::where('rep_id', $rep->id)->where('date', $date)->first();
        foreach ($archive?->points() ?? [] as $point) {
            $byTime[$point->ts] = $point;
        }

        foreach ($this->rawPoints($rep, $date) as $point) {
            $byTime[$point->ts] = $point;
        }

        ksort($byTime);

        return array_values($byTime);
    }

    /** @return list<Point> */
    public function rawPoints(Rep $rep, string $date): array
    {
        [$from, $to] = LocalDay::utcRange($date);

        return DB::table('location_points')
            ->where('rep_id', $rep->id)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $to)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (object $row) => Point::fromDatabase($row))
            ->all();
    }
}
