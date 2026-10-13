<?php

namespace App\Tracking;

use RuntimeException;

/**
 * Packs a day of points into a small binary string, and back, without losing anything.
 *
 * Why it is small: a walking rep's next point is only a few seconds and a few metres away
 * from the previous one. So instead of full values we store the *difference* from the
 * previous point, which is a small number and fits in 1-2 bytes (a "varint"). The result
 * is then compressed with gzip (deflate).
 *
 * Layout before compression:
 *   number of points
 *   all timestamp differences
 *   all latitude differences
 *   all longitude differences
 *   all accuracies (0 = unknown, otherwise accuracy + 1)
 *
 * Storing each field together (instead of point by point) puts similar numbers next to each
 * other, which gzip compresses much better.
 */
final class TrackCodec
{
    /** @param list<Point> $points sorted by time */
    public static function encode(array $points): string
    {
        $times = $lats = $lngs = $accuracies = '';
        $previous = new Point(0, 0, 0);

        foreach ($points as $point) {
            $times .= self::writeNumber($point->ts - $previous->ts);
            $lats .= self::writeNumber(self::toUnsigned($point->latE7 - $previous->latE7));
            $lngs .= self::writeNumber(self::toUnsigned($point->lngE7 - $previous->lngE7));
            $accuracies .= self::writeNumber($point->accuracyM === null ? 0 : $point->accuracyM + 1);
            $previous = $point;
        }

        return gzdeflate(self::writeNumber(count($points)).$times.$lats.$lngs.$accuracies, 9);
    }

    /** @return list<Point> */
    public static function decode(string $blob): array
    {
        $data = gzinflate($blob);
        if ($data === false) {
            throw new RuntimeException('The archived track is corrupt.');
        }

        $offset = 0;
        $count = self::readNumber($data, $offset);

        $times = self::readDeltas($data, $offset, $count, signed: false);
        $lats = self::readDeltas($data, $offset, $count, signed: true);
        $lngs = self::readDeltas($data, $offset, $count, signed: true);

        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $accuracy = self::readNumber($data, $offset);
            $points[] = new Point($times[$i], $lats[$i], $lngs[$i], $accuracy === 0 ? null : $accuracy - 1);
        }

        return $points;
    }

    /**
     * A fingerprint of the points. Used to prove that the archive decodes to exactly the
     * data it was built from.
     *
     * @param  list<Point>  $points
     */
    public static function checksum(array $points): string
    {
        $text = '';
        foreach ($points as $p) {
            $text .= "{$p->ts},{$p->latE7},{$p->lngE7},{$p->accuracyM}\n";
        }

        return hash('sha256', $text);
    }

    /** Reads $count differences and adds them up again into the original values. */
    private static function readDeltas(string $data, int &$offset, int $count, bool $signed): array
    {
        $values = [];
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $number = self::readNumber($data, $offset);
            $value += $signed ? self::toSigned($number) : $number;
            $values[] = $value;
        }

        return $values;
    }

    // Differences can be negative; "zigzag" maps 0, -1, 1, -2, 2 ... to 0, 1, 2, 3, 4 ...
    private static function toUnsigned(int $n): int
    {
        return $n >= 0 ? $n * 2 : -$n * 2 - 1;
    }

    private static function toSigned(int $n): int
    {
        return $n % 2 === 0 ? intdiv($n, 2) : -intdiv($n + 1, 2);
    }

    // Varint: 7 bits per byte, the high bit says "another byte follows".
    // Numbers below 128 take 1 byte, below 16,384 take 2 bytes, and so on.
    private static function writeNumber(int $n): string
    {
        $bytes = '';
        while ($n >= 128) {
            $bytes .= chr(($n % 128) + 128);
            $n = intdiv($n, 128);
        }

        return $bytes.chr($n);
    }

    private static function readNumber(string $data, int &$offset): int
    {
        $n = 0;
        $multiplier = 1;
        do {
            if ($offset >= strlen($data)) {
                throw new RuntimeException('The archived track is truncated.');
            }
            $byte = ord($data[$offset++]);
            $n += ($byte % 128) * $multiplier;
            $multiplier *= 128;
        } while ($byte >= 128);

        return $n;
    }
}
