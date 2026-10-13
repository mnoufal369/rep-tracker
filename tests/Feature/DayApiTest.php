<?php

namespace Tests\Feature;

use App\Models\Rep;
use App\Tracking\Simulation\DeviceUploadSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayApiTest extends TestCase
{
    use RefreshDatabase;

    private Rep $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 12:00:00');
        $this->rep = Rep::factory()->create();
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-10-09', seed: 3);
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-10-12', seed: 4);
        $this->artisan('tracking:archive');
        app(DeviceUploadSimulator::class)->simulate($this->rep, '2026-10-13', seed: 5);
    }

    public function test_lists_archived_and_live_days(): void
    {
        $this->getJson("/api/v1/reps/{$this->rep->id}/days?from=2026-10-01&to=2026-10-13")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.date', '2026-10-13')
            ->assertJsonPath('data.0.archived', false)
            ->assertJsonPath('data.1.date', '2026-10-12')
            ->assertJsonPath('data.1.archived', true);
    }

    public function test_day_detail_has_summary_and_timeline(): void
    {
        $response = $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12")->assertOk();

        $response->assertJsonPath('storage.archived', true)
            ->assertJsonStructure([
                'rep' => ['id', 'name', 'timezone'],
                'summary' => ['point_count', 'distance_m', 'moving_seconds', 'stop_count', 'gap_count'],
                'timeline' => [['type', 'start', 'end', 'duration_seconds']],
            ]);

        $timeline = $response->json('timeline');
        $this->assertSame($response->json('summary.stop_count'), count(array_filter($timeline, fn ($s) => $s['type'] === 'stop')));
        // Timeline entries are contiguous in time.
        for ($i = 1; $i < count($timeline); $i++) {
            $this->assertLessThanOrEqual(strtotime($timeline[$i]['start']), strtotime($timeline[$i - 1]['end']));
        }
    }

    public function test_track_is_geojson(): void
    {
        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12/track")
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonStructure(['features' => [['type', 'geometry' => ['type', 'coordinates'], 'properties' => ['type', 'start']]]]);
    }

    public function test_points_export_returns_every_fix(): void
    {
        $count = $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12")->json('summary.point_count');

        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12/points")
            ->assertOk()
            ->assertJsonPath('count', $count)
            ->assertJsonCount($count, 'data');

        $csv = $this->get("/api/v1/reps/{$this->rep->id}/days/2026-10-12/points?format=csv")->assertOk()->streamedContent();
        $this->assertSame($count + 1, substr_count(trim($csv), "\n") + 1);
    }

    public function test_empty_day_and_invalid_input(): void
    {
        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-10")->assertOk()->assertJsonPath('summary.point_count', 0);
        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-02-31")->assertUnprocessable();
        $this->getJson('/api/v1/reps/999/days/2026-10-12')->assertNotFound();
        $this->postJson("/api/v1/reps/{$this->rep->id}/days/2026-10-20/simulate")->assertUnprocessable();
    }
}
