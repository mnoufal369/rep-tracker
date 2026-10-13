<?php

namespace Tests\Unit;

use App\Tracking\Point;
use App\Tracking\TrackCodec;
use PHPUnit\Framework\TestCase;

class TrackCodecTest extends TestCase
{
    public function test_round_trip_is_lossless(): void
    {
        $points = [
            new Point(1760000000, 523745123, 48810456, 8, 140, 28, 97),
            new Point(1760000005, 523745500, 48810999, null, 0, null, 97),
            new Point(1760000065, -339000000, -1512000000, 65535, 65535, 359, 0),   // big jumps & extremes
            new Point(1760003000, 900000000, 1800000000, 1, null, 0, null),
            new Point(1760003001, -900000000, -1800000000, null, null, null, null),
        ];

        $decoded = TrackCodec::decode(TrackCodec::encode($points));

        $this->assertEquals($points, $decoded);
        $this->assertSame(TrackCodec::checksum($points), TrackCodec::checksum($decoded));
    }

    public function test_empty_day(): void
    {
        $this->assertSame([], TrackCodec::decode(TrackCodec::encode([])));
    }

    public function test_walking_track_compresses_to_a_few_bytes_per_fix(): void
    {
        $points = [];
        for ($i = 0; $i < 3000; $i++) {
            $points[] = new Point(1760000000 + $i * 5, 523745000 + $i * 50 + ($i * 7919 % 40), 48810000 + $i * 30, 5 + $i % 8, 130 + $i % 20, 90, 100 - intdiv($i, 60));
        }

        $bytes = strlen(TrackCodec::encode($points));

        $this->assertLessThan(8 * 3000, $bytes);
    }

    public function test_checksum_detects_any_change(): void
    {
        $a = [new Point(1760000000, 1, 2, 3, 4, 5, 6)];
        $b = [new Point(1760000000, 1, 2, 3, 4, 5, 7)];

        $this->assertNotSame(TrackCodec::checksum($a), TrackCodec::checksum($b));
    }
}
