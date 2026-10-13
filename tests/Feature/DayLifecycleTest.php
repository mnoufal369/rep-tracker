<?php

namespace Tests\Feature;

use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\DayTrackReader;
use App\Tracking\IngestionService;
use App\Tracking\Simulation\DeviceUploadSimulator;
use App\Tracking\TrackCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * hot ingest -> archive -> prune -> read back, and late data after archiving.
 */
class DayLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Rep $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 12:00:00');
        $this->rep = Rep::factory()->create();
    }

    public function test_simulated_upload_stores_every_generated_fix(): void
    {
        $result = app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-10-12', seed: 42);

        $this->assertGreaterThan(1000, $result['generated_points']);
        $this->assertSame($result['generated_points'], $result['upload']['stored']);
        $this->assertGreaterThan(0, $result['upload']['duplicates']);   // the retried batch
        $this->assertSame(0, $result['upload']['rejected']);
    }

    public function test_day_survives_archive_and_prune_bit_for_bit(): void
    {
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-06-01', seed: 7);
        $reader = app(DayTrackReader::class);
        $original = TrackCodec::checksum($reader->points($this->rep, '2026-06-01'));

        $this->artisan('tracking:archive')->assertSuccessful();

        $track = DailyTrack::firstOrFail();
        $this->assertSame($original, $track->checksum);
        $this->assertDatabaseCount('pending_archive_days', 0);

        $this->artisan('tracking:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('location_points')->count());
        $this->assertSame($original, TrackCodec::checksum($reader->points($this->rep, '2026-06-01')));
    }

    public function test_prune_archives_a_day_that_was_never_archived_before_deleting_it(): void
    {
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-06-01', seed: 7);
        $original = TrackCodec::checksum(app(DayTrackReader::class)->points($this->rep, '2026-06-01'));
        DB::table('pending_archive_days')->delete();   // simulate a lost queue entry

        $this->artisan('tracking:prune')->assertSuccessful();

        $this->assertSame($original, DailyTrack::firstOrFail()->checksum);
    }

    public function test_recent_days_are_not_archived_or_pruned(): void
    {
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-10-13');

        $this->artisan('tracking:archive')->assertSuccessful();
        $this->artisan('tracking:prune')->assertSuccessful();

        $this->assertDatabaseCount('daily_tracks', 0);
        $this->assertGreaterThan(0, DB::table('location_points')->count());
    }

    public function test_late_upload_for_an_archived_day_is_merged_into_the_archive(): void
    {
        $ingest = app(IngestionService::class);
        $fix = fn (string $time) => ['recorded_at' => "2026-06-01T{$time}Z", 'lat' => 52.37, 'lng' => 4.89];

        $ingest->ingest($this->rep, [$fix('08:00:00'), $fix('08:00:05')]);
        $this->artisan('tracking:archive');
        $this->artisan('tracking:prune');
        $this->assertSame(2, DailyTrack::firstOrFail()->point_count);

        // Phone comes back online months later with fixes from that day still buffered.
        $ingest->ingest($this->rep, [$fix('08:00:05'), $fix('15:00:00')]);
        $this->assertDatabaseHas('pending_archive_days', ['rep_id' => $this->rep->id, 'date' => '2026-06-01']);
        $this->assertCount(3, app(DayTrackReader::class)->points($this->rep, '2026-06-01'));

        $this->artisan('tracking:archive');
        $this->artisan('tracking:prune');

        $this->assertSame(3, DailyTrack::firstOrFail()->point_count);
        $this->assertCount(3, app(DayTrackReader::class)->points($this->rep, '2026-06-01'));
    }
}
