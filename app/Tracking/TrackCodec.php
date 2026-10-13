<?php

declare(strict_types=1);

namespace App\Tracking;

use InvalidArgumentException;

/**
 * Lossless compact binary encoding of a day of GPS fixes.
 *
 * Layout (version 1), before deflate:
 *   varint count
 *   then one column per field, each `count` values long:
 *     ts       delta from previous fix (first is absolute), unsigned varint
 *     lat/lng  zig-zag varint delta in 1e-7 degrees
 *     accuracy, speed, heading, battery
 *              0 = null, otherwise zig-zag delta from the previous non-null value, +1
 *
 * Consecutive fixes of a walking person differ by a few seconds and a few metres, so most
 * deltas fit in 1-2 bytes, and storing the data column-wise lets deflate find the long runs
 * (e.g. a constant 5 s interval, an unchanging battery level). Every bit of the source data
 * survives a round trip; nothing is rounded or simplified.
 */
final class TrackCodec
{
    public const VERSION = 1;

    /** @param list<Point> $points sorted by timestamp, unique */
    public static function encode(array $points): string
    {
        $columns = array_fill(0, 7, '');
        $prev = [0, 0, 0];
        $prevOptional = [0, 0, 0, 0];

        foreach ($points as $p) {
            $columns[0] .= self::varint($p->ts - $prev[0]);
            $columns[1] .= self::varint(self::zigzag($p->latE7 - $prev[1]));
            $columns[2] .= self::varint(self::zigzag($p->lngE7 - $prev[2]));
            $prev = [$p->ts, $p->latE7, $p->lngE7];

            foreach ([$p->accuracyM, $p->speedCms, $p->headingDeg, $p->batteryPct] as $i => $value) {
                if ($value === null) {
                    $columns[3 + $i] .= "\x00";

                    continue;
                }
                $columns[3 + $i] .= self::varint(self::zigzag($value - $prevOptional[$i]) + 1);
                $prevOptional[$i] = $value;
            }
        }

        $raw = self::varint(count($points)).implode('', $columns);

        return gzdeflate($raw, 9);
    }

    /** @return list<Point> */
    public static function decode(string $blob, int $version = self::VERSION): array
    {
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("Unsupported track codec version {$version}.");
        }

        $raw = gzinflate($blob);
        if ($raw === false) {
            throw new InvalidArgumentException('Corrupt track blob.');
        }

        $offset = 0;
        $count = self::readVarint($raw, $offset);
        $columns = array_fill(0, 7, []);

        $acc = 0;
        for ($i = 0; $i < $count; $i++) {
            $acc += self::readVarint($raw, $offset);
            $columns[0][] = $acc;
        }
        foreach ([1, 2] as $c) {
            $acc = 0;
            for ($i = 0; $i < $count; $i++) {
                $acc += self::unzigzag(self::readVarint($raw, $offset));
                $columns[$c][] = $acc;
            }
        }
        foreach ([3, 4, 5, 6] as $c) {
            $acc = 0;
            for ($i = 0; $i < $count; $i++) {
                $v = self::readVarint($raw, $offset);
                if ($v === 0) {
                    $columns[$c][] = null;

                    continue;
                }
                $acc += self::unzigzag($v - 1);
                $columns[$c][] = $acc;
            }
        }

        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $points[] = new Point(
                $columns[0][$i], $columns[1][$i], $columns[2][$i],
                $columns[3][$i], $columns[4][$i], $columns[5][$i], $columns[6][$i],
            );
        }

        return $points;
    }

    /**
     * Stable fingerprint of a point list, used to prove an archive matches its source.
     *
     * @param  list<Point>  $points
     */
    public static function checksum(array $points): string
    {
        $ctx = hash_init('sha256');
        foreach ($points as $p) {
            hash_update($ctx, "{$p->ts},{$p->latE7},{$p->lngE7},{$p->accuracyM},{$p->speedCms},{$p->headingDeg},{$p->batteryPct}\n");
        }

        return hash_final($ctx);
    }

    private static function zigzag(int $n): int
    {
        return ($n << 1) ^ ($n >> 63);
    }

    private static function unzigzag(int $z): int
    {
        return ($z >> 1) ^ -($z & 1);
    }

    private static function varint(int $n): string
    {
        if ($n < 0) {
            throw new InvalidArgumentException('varint must be unsigned; points must be sorted by time.');
        }

        $out = '';
        while ($n >= 0x80) {
            $out .= chr(($n & 0x7F) | 0x80);
            $n >>= 7;
        }

        return $out.chr($n);
    }

    private static function readVarint(string $buf, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        do {
            if (! isset($buf[$offset])) {
                throw new InvalidArgumentException('Truncated track blob.');
            }
            $byte = ord($buf[$offset++]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $result;
    }
}
