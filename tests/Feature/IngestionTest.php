<?php

namespace Tests\Feature;

use App\Models\Rep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    private Rep $rep;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 12:00:00');
        $this->rep = Rep::factory()->create();
        $this->token = $this->rep->issueDeviceToken();
    }

    public function test_requires_a_valid_device_token(): void
    {
        $this->postJson('/api/v1/locations', ['points' => [$this->fix(0)]])->assertUnauthorized();
        $this->withToken('nope')->postJson('/api/v1/locations', ['points' => [$this->fix(0)]])->assertUnauthorized();
    }

    public function test_stores_a_batch_and_queues_the_day_for_archiving(): void
    {
        $this->upload([$this->fix(0), $this->fix(5), $this->fix(10)])
            ->assertCreated()
            ->assertJson(['received' => 3, 'stored' => 3, 'duplicates' => 0, 'rejected' => [], 'days' => ['2026-10-13']]);

        $this->assertSame(3, DB::table('location_points')->where('rep_id', $this->rep->id)->count());
        $this->assertDatabaseHas('pending_archive_days', ['rep_id' => $this->rep->id, 'date' => '2026-10-13']);

        $row = DB::table('location_points')->orderBy('recorded_at')->first();
        $this->assertSame(523745123, (int) $row->lat_e7);
        $this->assertSame(140, (int) $row->speed_cms);
    }

    public function test_retrying_a_batch_is_idempotent(): void
    {
        $batch = [$this->fix(0), $this->fix(5)];

        $this->upload($batch)->assertJson(['stored' => 2, 'duplicates' => 0]);
        $this->upload([...$batch, $this->fix(10)])->assertJson(['stored' => 1, 'duplicates' => 2]);

        $this->assertSame(3, DB::table('location_points')->count());
    }

    public function test_invalid_fixes_are_reported_without_losing_the_rest_of_the_batch(): void
    {
        $this->upload([
            $this->fix(0),
            ['recorded_at' => '2026-10-13T09:00:05Z', 'lat' => 123, 'lng' => 4.9],
            ['recorded_at' => 'not a date', 'lat' => 52.3, 'lng' => 4.9],
            ['recorded_at' => '2026-10-14T12:00:00Z', 'lat' => 52.3, 'lng' => 4.9],
            $this->fix(15),
        ])
            ->assertCreated()
            ->assertJsonPath('stored', 2)
            ->assertJsonPath('rejected.0.index', 1)
            ->assertJsonPath('rejected.1.index', 2)
            ->assertJsonPath('rejected.2.reason', 'recorded_at is in the future.');
    }

    public function test_accepts_unix_timestamps_and_assigns_the_local_day(): void
    {
        // 23:30 UTC on the 12th is already the 13th in Amsterdam (UTC+2).
        $this->upload([['recorded_at' => strtotime('2026-10-12 23:30:00 UTC'), 'lat' => 52.37, 'lng' => 4.89]])
            ->assertJson(['stored' => 1, 'days' => ['2026-10-13']]);
    }

    public function test_rejects_oversized_or_empty_batches(): void
    {
        $this->upload([])->assertUnprocessable();
        $this->upload(array_map(fn ($i) => $this->fix($i), range(0, 1000)))->assertUnprocessable();
    }

    private function upload(array $points)
    {
        return $this->withToken($this->token)->postJson('/api/v1/locations', ['points' => $points]);
    }

    private function fix(int $offsetSeconds): array
    {
        return [
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-13 09:00:00 UTC') + $offsetSeconds),
            'lat' => 52.3745123,
            'lng' => 4.8810456,
            'accuracy' => 8,
            'speed' => 1.4,
            'heading' => 90,
            'battery' => 88,
        ];
    }
}
