<?php

namespace Tests\Unit;

use App\Tracking\Analysis\Segment;
use App\Tracking\Analysis\TrackAnalyzer;
use App\Tracking\Geo;
use App\Tracking\Point;
use PHPUnit\Framework\TestCase;

class TrackAnalyzerTest extends TestCase
{
    private int $t = 1760000000;

    private float $lat = 52.37;

    private float $lng = 4.89;

    public function test_walk_stop_walk(): void
    {
        $points = [...$this->walk(0, 500), ...$this->stay(600), ...$this->walk(90, 300)];

        $analysis = (new TrackAnalyzer(50, 180, 300))->analyze($points);

        $this->assertSame([Segment::MOVE, Segment::STOP, Segment::MOVE], array_map(fn (Segment $s) => $s->type, $analysis->timeline));
        $this->assertSame(1, $analysis->stopCount);
        $this->assertSame(0, $analysis->gapCount);
        $this->assertEqualsWithDelta(800, $analysis->distanceM, 40);
        $this->assertEqualsWithDelta(600, $analysis->timeline[1]->duration(), 70);
    }

    public function test_short_pause_is_not_a_stop(): void
    {
        $points = [...$this->walk(0, 300), ...$this->stay(90), ...$this->walk(0, 300)];

        $analysis = (new TrackAnalyzer(50, 180, 300))->analyze($points);

        $this->assertSame(0, $analysis->stopCount);
    }

    public function test_missing_data_is_reported_as_a_gap(): void
    {
        $first = $this->walk(0, 300);
        $this->t += 900;
        [$this->lat, $this->lng] = Geo::offset($this->lat, $this->lng, 0, 400);
        $second = $this->walk(0, 300);

        $analysis = (new TrackAnalyzer(50, 180, 300))->analyze([...$first, ...$second]);

        $this->assertSame(1, $analysis->gapCount);
        $gap = $analysis->timeline[1];
        $this->assertSame(Segment::GAP, $gap->type);
        $this->assertGreaterThanOrEqual(900, $gap->duration());
        $this->assertEqualsWithDelta(400, $gap->distanceM, 10);
        // Distance walked only counts fixes we actually have.
        $this->assertEqualsWithDelta(600, $analysis->distanceM, 40);
    }

    public function test_empty_day(): void
    {
        $analysis = (new TrackAnalyzer)->analyze([]);

        $this->assertSame([], $analysis->timeline);
        $this->assertNull($analysis->bounds);
    }

    /** @return list<Point> */
    private function walk(float $bearing, float $meters): array
    {
        $points = [];
        for ($walked = 0; $walked < $meters; $walked += 6.5) {
            $this->t += 5;
            [$this->lat, $this->lng] = Geo::offset($this->lat, $this->lng, $bearing, 6.5);
            $points[] = $this->point(8);
        }

        return $points;
    }

    /** @return list<Point> */
    private function stay(int $seconds): array
    {
        $points = [];
        for ($s = 0; $s < $seconds; $s += 60) {
            $this->t += 60;
            $points[] = $this->point(20, jitter: 12);
        }

        return $points;
    }

    private function point(int $accuracy, float $jitter = 0): Point
    {
        [$lat, $lng] = $jitter ? Geo::offset($this->lat, $this->lng, ($this->t * 37) % 360, $jitter) : [$this->lat, $this->lng];

        return new Point($this->t, (int) round($lat * 1e7), (int) round($lng * 1e7), $accuracy);
    }
}
