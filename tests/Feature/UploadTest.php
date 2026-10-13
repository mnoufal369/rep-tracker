<?php

namespace Tests\Feature;

use App\Models\Rep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 12:00:00');
        $this->token = Rep::factory()->create()->issueDeviceToken();
    }

    public function test_a_device_token_is_required(): void
    {
        $this->postJson('/api/v1/locations', ['points' => [$this->point('09:00:00')]])->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/v1/locations', ['points' => [$this->point('09:00:00')]])->assertUnauthorized();
    }

    public function test_points_are_stored_and_the_day_is_queued_for_archiving(): void
    {
        $this->upload([$this->point('09:00:00'), $this->point('09:00:05')])
            ->assertCreated()
            ->assertJson(['received' => 2, 'stored' => 2, 'duplicates' => 0, 'rejected' => []]);

        $this->assertSame(2, DB::table('location_points')->count());
        $this->assertDatabaseHas('pending_archive_days', ['date' => '2026-10-13']);
    }

    public function test_sending_the_same_batch_again_stores_nothing_twice(): void
    {
        $batch = [$this->point('09:00:00'), $this->point('09:00:05')];

        $this->upload($batch)->assertJson(['stored' => 2]);
        $this->upload([...$batch, $this->point('09:00:10')])->assertJson(['stored' => 1, 'duplicates' => 2]);

        $this->assertSame(3, DB::table('location_points')->count());
    }

    public function test_bad_points_are_reported_and_the_good_ones_are_still_stored(): void
    {
        $this->upload([
            $this->point('09:00:00'),
            ['recorded_at' => '2026-10-13T09:00:05Z', 'lat' => 123, 'lng' => 4.9],
            ['recorded_at' => 'yesterday-ish', 'lat' => 52.3, 'lng' => 4.9],
            ['recorded_at' => '2026-10-14T09:00:00Z', 'lat' => 52.3, 'lng' => 4.9],
        ])
            ->assertCreated()
            ->assertJsonPath('stored', 1)
            ->assertJsonPath('rejected.0.index', 1)
            ->assertJsonPath('rejected.1.index', 2)
            ->assertJsonPath('rejected.2.reason', 'recorded_at is in the future.');
    }

    public function test_the_day_is_the_local_amsterdam_day(): void
    {
        // 23:30 UTC on the 12th is 01:30 on the 13th in Amsterdam.
        $this->upload([['recorded_at' => strtotime('2026-10-12 23:30:00 UTC'), 'lat' => 52.37, 'lng' => 4.89]]);

        $this->assertDatabaseHas('pending_archive_days', ['date' => '2026-10-13']);
    }

    public function test_empty_and_too_large_batches_are_refused(): void
    {
        $this->upload([])->assertUnprocessable();
        $this->upload(array_fill(0, 1001, $this->point('09:00:00')))->assertUnprocessable();
    }

    private function upload(array $points)
    {
        return $this->withToken($this->token)->postJson('/api/v1/locations', ['points' => $points]);
    }

    private function point(string $time): array
    {
        return ['recorded_at' => "2026-10-13T{$time}Z", 'lat' => 52.3745123, 'lng' => 4.8810456, 'accuracy' => 8];
    }
}
