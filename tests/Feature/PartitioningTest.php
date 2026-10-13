<?php

namespace Tests\Feature;

use App\Models\Rep;
use App\Tracking\DayTrackReader;
use App\Tracking\IngestionService;
use App\Tracking\Storage\PartitionManager;
use App\Tracking\TrackCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs only against MySQL: `composer test:mysql`.
 */
class PartitioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Partitioning is MySQL-only; run `composer test:mysql`.');
        }
    }

    public function test_old_months_are_dropped_as_partitions_after_archiving(): void
    {
        $partitions = app(PartitionManager::class);
        $rep = Rep::factory()->create();
        $partitions->ensureRange(now()->subYears(3), now()->addMonths(3));

        $fixes = fn (string $day) => array_map(
            fn ($i) => ['recorded_at' => strtotime("{$day} 09:00:00 UTC") + $i * 5, 'lat' => 52.37 + $i / 1e4, 'lng' => 4.89],
            range(0, 99),
        );
        app(IngestionService::class)->ingest($rep, $fixes(now()->subYears(2)->format('Y-m-d')));
        app(IngestionService::class)->ingest($rep, $fixes(now()->subDays(2)->format('Y-m-d')));
        $oldDay = now()->subYears(2)->format('Y-m-d');
        $checksum = TrackCodec::checksum(app(DayTrackReader::class)->points($rep, $oldDay));
        $oldPartition = 'p'.now()->subYears(2)->format('Ym');

        $this->assertContains($oldPartition, array_column($partitions->partitions(), 'name'));

        $this->artisan('tracking:prune')->assertSuccessful();

        $names = array_column($partitions->partitions(), 'name');
        $this->assertNotContains($oldPartition, $names);
        $this->assertContains('p'.now()->format('Ym'), $names);
        $this->assertContains('p_future', $names);
        $this->assertSame(100, DB::table('location_points')->count());   // only the recent day is left
        $this->assertSame($checksum, TrackCodec::checksum(app(DayTrackReader::class)->points($rep, $oldDay)));

        // A late upload for the dropped month still has a partition to land in.
        app(IngestionService::class)->ingest($rep, [['recorded_at' => "{$oldDay}T15:00:00Z", 'lat' => 52.37, 'lng' => 4.89]]);
        $this->assertCount(101, app(DayTrackReader::class)->points($rep, $oldDay));
    }
}
