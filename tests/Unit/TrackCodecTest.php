<?php

namespace Tests\Unit;

use App\Tracking\Point;
use App\Tracking\TrackCodec;
use PHPUnit\Framework\TestCase;

class TrackCodecTest extends TestCase
{
    public function test_decoding_gives_back_exactly_the_same_points(): void
    {
        $points = [
            new Point(1760000000, 523745123, 48810456, 8),
            new Point(1760000005, 523745500, 48810999, null),
            new Point(1760000065, -339000000, -1512000000, 65535),   // big jump, negative values
            new Point(1760003000, 900000000, 1800000000, 0),
        ];

        $this->assertEquals($points, TrackCodec::decode(TrackCodec::encode($points)));
    }

    public function test_empty_day(): void
    {
        $this->assertSame([], TrackCodec::decode(TrackCodec::encode([])));
    }

    public function test_a_walking_day_takes_only_a_few_bytes_per_point(): void
    {
        $points = [];
        for ($i = 0; $i < 3000; $i++) {
            $points[] = new Point(1760000000 + $i * 5, 523745000 + $i * 60 + $i % 37, 48810000 + $i * 40, 8);
        }

        $this->assertLessThan(5 * 3000, strlen(TrackCodec::encode($points)));
    }

    public function test_checksum_changes_when_any_value_changes(): void
    {
        $this->assertNotSame(
            TrackCodec::checksum([new Point(1760000000, 1, 2, 3)]),
            TrackCodec::checksum([new Point(1760000000, 1, 2, 4)]),
        );
    }
}
