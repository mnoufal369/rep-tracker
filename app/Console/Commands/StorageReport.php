<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('tracking:storage')]
#[Description('Compare the storage cost of raw (hot) and archived (cold) location data')]
class StorageReport extends Command
{
    public function handle(): int
    {
        $hotRows = DB::table('location_points')->count();
        $archive = DB::table('daily_tracks')->selectRaw('COUNT(*) AS days, COALESCE(SUM(point_count), 0) AS points, COALESCE(SUM(LENGTH(points_blob)), 0) AS blob_bytes')->first();

        $rows = [
            ['Hot: location_points rows', number_format($hotRows)],
            ['Archived rep-days', number_format($archive->days)],
            ['Archived points', number_format($archive->points)],
            ['Archive blob bytes / point', $archive->points ? round($archive->blob_bytes / $archive->points, 2) : '-'],
        ];

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ANALYZE TABLE location_points, daily_tracks');
            $sizes = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
                ->whereIn('TABLE_NAME', ['location_points', 'daily_tracks'])
                ->pluck(DB::raw('DATA_LENGTH + INDEX_LENGTH'), 'TABLE_NAME');
            $hotBytes = (int) ($sizes['location_points'] ?? 0);
            $rows[] = ['Hot table size (InnoDB data + index)', number_format($hotBytes / 1048576, 2).' MB'];
            $rows[] = ['Hot bytes / point', $hotRows ? round($hotBytes / $hotRows, 2) : '-'];
            $rows[] = ['Archive table size', number_format(($sizes['daily_tracks'] ?? 0) / 1048576, 2).' MB'];
            if ($hotRows && $archive->points) {
                $rows[] = ['Compression ratio (hot vs archive)', round(($hotBytes / $hotRows) / ($archive->blob_bytes / $archive->points), 1).'x'];
            }
        }

        $this->table(['Metric', 'Value'], $rows);

        return self::SUCCESS;
    }
}
