<?php

declare(strict_types=1);

namespace App\Tracking\Storage;

use App\Models\Rep;
use App\Tracking\DayArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Removes raw fixes that are older than the hot retention window — but only after every
 * rep-day they belong to has a verified, up-to-date archive.
 *
 * On MySQL whole monthly partitions are dropped (instant, no fragmentation, no long-running
 * DELETE); only the few stray rows of late uploads are range-deleted. Other drivers fall
 * back to range deletes.
 */
final class HotStoragePruner
{
    public function __construct(
        private readonly DayArchiver $archiver,
        private readonly PartitionManager $partitions,
    ) {}

    /** @return list<string> human-readable description of what was (or would be) removed */
    public function prune(bool $dryRun = false): array
    {
        $cutoff = CarbonImmutable::today('UTC')->subDays(config('tracking.hot_retention_days'));

        return $this->partitions->supported()
            ? $this->dropPartitions($cutoff, $dryRun)
            : $this->deleteRows($cutoff, $dryRun);
    }

    /** @return list<string> */
    private function dropPartitions(CarbonImmutable $cutoff, bool $dryRun): array
    {
        $log = [];
        $empty = 0;
        $lowerBound = '1970-01-01 00:00:00';

        foreach ($this->partitions->partitions() as $partition) {
            if ($partition['upper_bound'] === null || CarbonImmutable::parse($partition['upper_bound'], 'UTC')->greaterThan($cutoff)) {
                break;
            }
            $days = $this->ensureArchived($lowerBound, $partition['upper_bound'], $dryRun);
            $days ? $log[] = "drop partition {$partition['name']} (~{$partition['rows']} rows covering {$days} archived rep-days)" : $empty++;
            if (! $dryRun) {
                $this->partitions->drop($partition['name']);
            }
            $lowerBound = $partition['upper_bound'];
        }

        if ($empty) {
            $log[] = "drop {$empty} empty partition(s)";
        }

        // Late uploads for already-dropped months land in the oldest remaining partition: those
        // few stray rows (older than that partition's own month) are range-deleted.
        $first = collect($this->partitions->partitions())->firstWhere('name', '!=', 'p_future');
        if ($first && $first['upper_bound'] !== null) {
            $firstMonth = CarbonImmutable::parse($first['upper_bound'], 'UTC')->subMonth();
            $log = [...$log, ...$this->deleteRows($firstMonth->min($cutoff), $dryRun)];
        }

        return $log;
    }

    /** @return list<string> */
    private function deleteRows(CarbonImmutable $cutoff, bool $dryRun): array
    {
        $days = $this->ensureArchived('1970-01-01 00:00:00', $cutoff->format('Y-m-d H:i:s'), $dryRun);
        $rows = 0;
        if (! $dryRun) {
            $rows = DB::table('location_points')->where('recorded_at', '<', $cutoff->format('Y-m-d H:i:s'))->delete();
        }

        return $days ? ["delete {$rows} rows before {$cutoff->toDateString()} covering {$days} archived rep-days"] : [];
    }

    /**
     * Archives every rep-day that has raw data in [from, to) and is pending or not archived.
     *
     * @return int number of distinct rep-days in the range
     */
    private function ensureArchived(string $from, string $to, bool $dryRun): int
    {
        // 15-minute buckets: every real-world UTC offset is a multiple of 15 minutes, so each
        // bucket maps to exactly one local date whatever the rep's timezone.
        $bucket = DB::getDriverName() === 'mysql'
            ? "TIMESTAMPDIFF(MINUTE, '1970-01-01 00:00:00', recorded_at) DIV 15"
            : "CAST(strftime('%s', recorded_at) AS INTEGER) / 900";

        $buckets = DB::table('location_points')
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $to)
            ->groupBy('rep_id', 'bucket')
            ->get(['rep_id', DB::raw("{$bucket} AS bucket")]);

        $reps = Rep::whereIn('id', $buckets->pluck('rep_id')->unique())->get()->keyBy('id');
        $pairs = [];
        foreach ($buckets as $b) {
            $rep = $reps->get($b->rep_id);
            if ($rep) {
                $pairs[$rep->id.'|'.$rep->localDate((int) $b->bucket * 900)] = [$rep, $rep->localDate((int) $b->bucket * 900)];
            }
        }

        foreach ($pairs as [$rep, $date]) {
            $archived = DB::table('daily_tracks')->where(['rep_id' => $rep->id, 'date' => $date])->exists();
            $pending = DB::table('pending_archive_days')->where(['rep_id' => $rep->id, 'date' => $date])->exists();
            if (($pending || ! $archived) && ! $dryRun) {
                $this->archiver->archive($rep, $date);
            }
        }

        return count($pairs);
    }
}
