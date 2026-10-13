<?php

declare(strict_types=1);

namespace App\Tracking\Storage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly RANGE COLUMNS partitioning of `location_points` (MySQL only).
 *
 * Partition `pYYYYMM` holds that UTC month; `p_future` is a MAXVALUE catch-all that is
 * split ahead of time so it always stays empty. Rows older than the first partition's
 * bound land in the first partition, so late uploads never fail.
 */
final class PartitionManager
{
    private const TABLE = 'location_points';

    public function supported(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    public function partitionTable(CarbonInterface $from, CarbonInterface $to): void
    {
        $definitions = array_map(
            fn (CarbonImmutable $month) => $this->definition($month),
            $this->months($from, $to),
        );
        $definitions[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';

        DB::statement('ALTER TABLE '.self::TABLE.' PARTITION BY RANGE COLUMNS(recorded_at) ('.implode(', ', $definitions).')');
    }

    /**
     * Makes sure monthly partitions exist from $from up to $to (inclusive months).
     *
     * @return list<string> names of partitions created
     */
    public function ensureRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $existing = $this->partitions();
        $named = array_values(array_filter($existing, fn (array $p) => $p['name'] !== 'p_future'));
        $created = [];

        // Extend backwards by splitting the first partition (only needed for back-filling history).
        if ($named) {
            $first = $named[0];
            $firstMonth = CarbonImmutable::parse($first['upper_bound'])->subMonth();
            $earlier = $this->months($from, $firstMonth->subMonth());
            if ($earlier) {
                $definitions = array_map(fn ($m) => $this->definition($m), $earlier);
                $definitions[] = $this->definition($firstMonth);
                DB::statement('ALTER TABLE '.self::TABLE." REORGANIZE PARTITION {$first['name']} INTO (".implode(', ', $definitions).')');
                $created = array_map(fn ($m) => $this->name($m), $earlier);
            }
            $nextMonth = CarbonImmutable::parse($named[count($named) - 1]['upper_bound']);
        } else {
            $nextMonth = CarbonImmutable::parse($from)->startOfMonth();
        }

        // Extend forwards by splitting the empty p_future partition.
        $later = $this->months($nextMonth, $to);
        if ($later) {
            $definitions = array_map(fn ($m) => $this->definition($m), $later);
            $definitions[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';
            DB::statement('ALTER TABLE '.self::TABLE.' REORGANIZE PARTITION p_future INTO ('.implode(', ', $definitions).')');
            $created = [...$created, ...array_map(fn ($m) => $this->name($m), $later)];
        }

        return $created;
    }

    /**
     * @return list<array{name: string, upper_bound: string|null, rows: int, bytes: int}>
     */
    public function partitions(): array
    {
        return DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', self::TABLE)
            ->whereNotNull('PARTITION_NAME')
            ->orderBy('PARTITION_ORDINAL_POSITION')
            ->get(['PARTITION_NAME', 'PARTITION_DESCRIPTION', 'TABLE_ROWS', 'DATA_LENGTH', 'INDEX_LENGTH'])
            ->map(fn ($p) => [
                'name' => $p->PARTITION_NAME,
                'upper_bound' => $p->PARTITION_DESCRIPTION === 'MAXVALUE' ? null : trim($p->PARTITION_DESCRIPTION, "'"),
                'rows' => (int) $p->TABLE_ROWS,
                'bytes' => (int) $p->DATA_LENGTH + (int) $p->INDEX_LENGTH,
            ])
            ->all();
    }

    public function drop(string $name): void
    {
        DB::statement('ALTER TABLE '.self::TABLE." DROP PARTITION {$name}");
    }

    /** @return list<CarbonImmutable> first day of every month in [from, to] */
    private function months(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $month = CarbonImmutable::parse($from)->utc()->startOfMonth();
        $last = CarbonImmutable::parse($to)->utc()->startOfMonth();
        while ($month->lessThanOrEqualTo($last)) {
            $months[] = $month;
            $month = $month->addMonth();
        }

        return $months;
    }

    private function name(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }

    private function definition(CarbonImmutable $month): string
    {
        return "PARTITION {$this->name($month)} VALUES LESS THAN ('{$month->addMonth()->format('Y-m-d')}')";
    }
}
