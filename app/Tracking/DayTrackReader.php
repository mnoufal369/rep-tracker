<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Models\DailyTrack;
use App\Models\Rep;
use Illuminate\Support\Facades\DB;

/**
 * Read path: returns every fix of a rep's local day, wherever it currently lives.
 *
 * A day can be in hot storage (raw rows), cold storage (archive blob) or both (archived but
 * not yet pruned, or late data arriving for an archived day). Both sources are merged and
 * de-duplicated on timestamp, so callers never need to know where the data is.
 */
final class DayTrackReader
{
    /** @return list<Point> */
    public function points(Rep $rep, string $date): array
    {
        $byTs = [];

        $archive = DailyTrack::where('rep_id', $rep->id)->where('date', $date)->first();
        foreach ($archive?->points() ?? [] as $p) {
            $byTs[$p->ts] = $p;
        }

        foreach ($this->rawPoints($rep, $date) as $p) {
            $byTs[$p->ts] = $p;
        }

        ksort($byTs);

        return array_values($byTs);
    }

    /** @return list<Point> */
    public function rawPoints(Rep $rep, string $date): array
    {
        [$from, $to] = $rep->dayBounds($date);

        return DB::table('location_points')
            ->where('rep_id', $rep->id)
            ->where('recorded_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('recorded_at', '<', $to->format('Y-m-d H:i:s'))
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (object $row) => Point::fromRow($row))
            ->all();
    }
}
