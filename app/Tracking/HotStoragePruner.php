<?php

namespace App\Tracking;

use Illuminate\Support\Facades\DB;

/**
 * Deletes raw points older than `tracking.hot_retention_days`, once they are safely archived.
 */
final class HotStoragePruner
{
    public function __construct(
        private readonly DayArchiver $archiver,
        private readonly PartitionManager $partitions,
    ) {}

    /** @return int number of partitions dropped */
    public function prune(): int
    {
        $cutoffDate = now(config('tracking.timezone'))->subDays(config('tracking.hot_retention_days'))->toDateString();
        [$cutoff] = LocalDay::utcRange($cutoffDate);

        // 1. Every raw point is either archived already or its day is pending.
        //    Archive the pending days first, so nothing is deleted before it is archived.
        $this->archiver->archivePending(beforeDate: $cutoffDate);

        // 2. Drop whole months that are completely older than the cutoff (instant on MySQL).
        $dropped = 0;
        if ($this->partitions->enabled()) {
            foreach ($this->partitions->partitions() as $partition) {
                if ($partition['ends_at'] === null || $partition['ends_at'] > $cutoff) {
                    break;
                }
                $this->partitions->drop($partition['name']);
                $dropped++;
            }
        }

        // 3. Delete what is left before the cutoff: the first days of the current oldest month.
        DB::table('location_points')->where('recorded_at', '<', $cutoff)->delete();

        return $dropped;
    }
}
