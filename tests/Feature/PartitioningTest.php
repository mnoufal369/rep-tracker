<?php

namespace Tests\Feature;

use App\Models\Rep;
use App\Tracking\DayTrackReader;
use App\Tracking\IngestionService;
use App\Tracking\PartitionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MySQL only. Run with: composer test:mysql
 */
class PartitioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_months_are_dropped_and_still_readable_from_the_archive(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Partitions are MySQL only.');
        }

        $rep = Rep::factory()->create();
        $oldDay = now()->subYears(2)->toDateString();
        $recentDay = now()->subDays(2)->toDateString();
        $points = fn (string $day) => array_map(
            fn ($i) => ['recorded_at' => strtotime("{$day} 09:00:00 UTC") + $i * 5, 'lat' => 52.37 + $i / 10000, 'lng' => 4.89],
            range(0, 99),
        );
        app(IngestionService::class)->store($rep, $points($oldDay));
        app(IngestionService::class)->store($rep, $points($recentDay));
        $oldPartition = 'p'.now()->subYears(2)->format('Ym');

        $this->artisan('tracking:prune')->assertSuccessful();

        $names = array_column(app(PartitionManager::class)->partitions(), 'name');
        $this->assertNotContains($oldPartition, $names);
        $this->assertContains('p_future', $names);
        $this->assertSame(100, DB::table('location_points')->count());   // only the recent day is still raw
        $this->assertCount(100, app(DayTrackReader::class)->points($rep, $oldDay));
    }
}
