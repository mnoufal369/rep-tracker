<?php

namespace App\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Splits `location_points` into one partition per month (MySQL only).
 *
 * Partition p202610 holds October 2026 (UTC). p_future catches anything later and is kept
 * empty by adding the coming months ahead of time. Removing a whole old month is then a
 * single, instant DROP PARTITION instead of deleting millions of rows.
 */
final class PartitionManager
{
    public function enabled(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /** Called once by the migration: 4 years back (for imports) up to 3 months ahead. */
    public function createPartitions(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $partitions = [];
        $month = CarbonImmutable::now('UTC')->subYears(4)->startOfMonth();
        $last = CarbonImmutable::now('UTC')->addMonths(3)->startOfMonth();
        for (; $month <= $last; $month = $month->addMonth()) {
            $partitions[] = $this->definition($month);
        }
        $partitions[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';

        DB::statement('ALTER TABLE location_points PARTITION BY RANGE COLUMNS(recorded_at) ('.implode(', ', $partitions).')');
    }

    /** Adds partitions for the coming months by splitting the (empty) p_future partition. */
    public function addUpcomingMonths(int $monthsAhead = 3): void
    {
        $lastBound = collect($this->partitions())->whereNotNull('ends_at')->last()['ends_at'];
        $month = CarbonImmutable::parse($lastBound, 'UTC');
        $until = CarbonImmutable::now('UTC')->addMonths($monthsAhead)->startOfMonth();

        $new = [];
        for (; $month <= $until; $month = $month->addMonth()) {
            $new[] = $this->definition($month);
        }

        if ($new) {
            $new[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';
            DB::statement('ALTER TABLE location_points REORGANIZE PARTITION p_future INTO ('.implode(', ', $new).')');
        }
    }

    /** @return list<array{name: string, ends_at: ?string}> oldest first; ends_at is exclusive */
    public function partitions(): array
    {
        return DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'location_points')
            ->orderBy('PARTITION_ORDINAL_POSITION')
            ->get(['PARTITION_NAME', 'PARTITION_DESCRIPTION'])
            ->map(fn ($p) => [
                'name' => $p->PARTITION_NAME,
                'ends_at' => $p->PARTITION_DESCRIPTION === 'MAXVALUE' ? null : trim($p->PARTITION_DESCRIPTION, "'"),
            ])
            ->all();
    }

    public function drop(string $name): void
    {
        DB::statement("ALTER TABLE location_points DROP PARTITION {$name}");
    }

    private function definition(CarbonImmutable $month): string
    {
        return sprintf("PARTITION p%s VALUES LESS THAN ('%s')", $month->format('Ym'), $month->addMonth()->format('Y-m-d'));
    }
}
