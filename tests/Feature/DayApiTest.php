<?php

namespace Tests\Feature;

use App\Models\Rep;
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
    }

    public function test_generate_then_inspect_a_day(): void
    {
        $generated = $this->postJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12/generate")
            ->assertCreated()
            ->json();
        $this->assertGreaterThan(1000, $generated['stored']);

        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12")
            ->assertOk()
            ->assertJsonPath('point_count', $generated['stored'])
            ->assertJsonPath('archived', false)
            ->assertJsonStructure(['first_at', 'last_at', 'distance_m', 'gap_count', 'gaps']);

        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12/points")
            ->assertOk()
            ->assertJsonCount($generated['stored'], 'data');
    }

    public function test_days_list_shows_archived_and_not_yet_archived_days(): void
    {
        $this->postJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12/generate");
        $this->artisan('tracking:archive');
        $this->postJson("/api/v1/reps/{$this->rep->id}/days/2026-10-13/generate");

        $this->getJson("/api/v1/reps/{$this->rep->id}/days")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-10-13')
            ->assertJsonPath('data.0.archived', false)
            ->assertJsonPath('data.1.date', '2026-10-12')
            ->assertJsonPath('data.1.archived', true);
    }

    public function test_a_gap_in_the_points_is_reported(): void
    {
        $token = $this->rep->issueDeviceToken();
        $this->withToken($token)->postJson('/api/v1/locations', ['points' => [
            ['recorded_at' => '2026-10-12T09:00:00Z', 'lat' => 52.37, 'lng' => 4.89],
            ['recorded_at' => '2026-10-12T09:20:00Z', 'lat' => 52.37, 'lng' => 4.89],
        ]]);

        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-10-12")
            ->assertJsonPath('gap_count', 1)
            ->assertJsonPath('gaps.0.duration_seconds', 1200);
    }

    public function test_invalid_requests(): void
    {
        $this->getJson("/api/v1/reps/{$this->rep->id}/days/2026-02-31")->assertUnprocessable();
        $this->getJson('/api/v1/reps/999/days/2026-10-12')->assertNotFound();
        $this->postJson("/api/v1/reps/{$this->rep->id}/days/2026-10-20/generate")->assertUnprocessable();
    }
}
