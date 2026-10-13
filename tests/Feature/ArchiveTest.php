<?php

namespace Tests\Feature;

use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\DayTrackReader;
use App\Tracking\IngestionService;
use App\Tracking\RouteGenerator;
use App\Tracking\TrackCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The full life of a day: upload -> archive -> delete raw points -> still readable.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Rep $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 12:00:00');
        $this->rep = Rep::factory()->create();
    }

    public function test_an_old_day_is_identical_after_archiving_and_deleting_the_raw_points(): void
    {
        $this->generate('2026-06-01');
        $before = $this->checksum('2026-06-01');

        $this->artisan('tracking:archive')->assertSuccessful();
        $this->artisan('tracking:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('location_points')->count());
        $this->assertSame($before, DailyTrack::firstOrFail()->checksum);
        $this->assertSame($before, $this->checksum('2026-06-01'));
    }

    public function test_today_is_not_archived_and_recent_days_are_not_deleted(): void
    {
        $this->generate('2026-10-12');
        $this->generate('2026-10-13');

        $this->artisan('tracking:archive');
        $this->artisan('tracking:prune');

        $this->assertSame(['2026-10-12'], DailyTrack::pluck('date')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertGreaterThan(0, DB::table('location_points')->count());   // both days are still raw too
    }

    public function test_pruning_archives_a_pending_day_before_deleting_it(): void
    {
        $this->generate('2026-06-01');
        $before = $this->checksum('2026-06-01');

        $this->artisan('tracking:prune');   // no tracking:archive first

        $this->assertSame($before, $this->checksum('2026-06-01'));
    }

    public function test_points_uploaded_long_after_the_day_are_added_to_its_archive(): void
    {
        $point = fn (string $time) => ['recorded_at' => "2026-06-01T{$time}Z", 'lat' => 52.37, 'lng' => 4.89];
        $ingestion = app(IngestionService::class);

        $ingestion->store($this->rep, [$point('08:00:00'), $point('08:00:05')]);
        $this->artisan('tracking:archive');
        $this->artisan('tracking:prune');

        // The phone was offline for months and still had these in its buffer.
        $ingestion->store($this->rep, [$point('08:00:05'), $point('15:00:00')]);
        $this->artisan('tracking:archive');
        $this->artisan('tracking:prune');

        $this->assertSame(3, DailyTrack::firstOrFail()->point_count);
        $this->assertCount(3, app(DayTrackReader::class)->points($this->rep, '2026-06-01'));
    }

    private function generate(string $date): void
    {
        $points = app(RouteGenerator::class)->generate($date, seed: 1);
        app(IngestionService::class)->store($this->rep, $points);
    }

    private function checksum(string $date): string
    {
        return TrackCodec::checksum(app(DayTrackReader::class)->points($this->rep, $date));
    }
}
