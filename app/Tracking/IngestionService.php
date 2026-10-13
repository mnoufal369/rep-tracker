<?php

namespace App\Tracking;

use App\Models\Rep;
use Illuminate\Support\Facades\DB;

/**
 * Stores points uploaded by a rep's phone.
 *
 * Safe to retry: a point that is already stored (same rep, same second) is skipped and
 * counted as a duplicate. So if the phone did not get our answer and sends the batch again,
 * nothing is stored twice and nothing is lost.
 */
final class IngestionService
{
    /**
     * @param  array<int, mixed>  $input  points as sent by the phone
     * @return array{received: int, stored: int, duplicates: int, rejected: list<array{index: int, reason: string}>}
     */
    public function store(Rep $rep, array $input): array
    {
        $points = [];
        $rejected = [];

        foreach ($input as $index => $raw) {
            $error = $this->validate($raw);
            if ($error !== null) {
                // Report the bad point but keep the rest of the batch.
                $rejected[] = ['index' => $index, 'reason' => $error];

                continue;
            }

            $point = $this->toPoint($raw);
            $points[$point->ts] ??= $point;   // the same second twice in one batch: keep the first
        }

        $days = array_unique(array_map(fn (Point $p) => LocalDay::of($p->ts), $points));

        $stored = DB::transaction(function () use ($rep, $points, $days) {
            $stored = 0;
            foreach (array_chunk($points, 500) as $chunk) {
                $rows = array_map(fn (Point $p) => $p->toDatabase($rep->id), $chunk);
                $stored += DB::table('location_points')->insertOrIgnore($rows);
            }

            // In the same transaction: remember that these days need (re)archiving.
            $pending = array_map(fn (string $day) => ['rep_id' => $rep->id, 'date' => $day], $days);
            DB::table('pending_archive_days')->insertOrIgnore(array_values($pending));

            return $stored;
        });

        return [
            'received' => count($input),
            'stored' => $stored,
            'duplicates' => count($input) - count($rejected) - $stored,
            'rejected' => $rejected,
        ];
    }

    private function validate(mixed $raw): ?string
    {
        if (! is_array($raw)) {
            return 'Point must be an object.';
        }

        $ts = $this->timestamp($raw['recorded_at'] ?? null);

        return match (true) {
            $ts === null => 'recorded_at must be an ISO-8601 date or a unix timestamp.',
            $ts > time() + 300 => 'recorded_at is in the future.',
            ! is_numeric($raw['lat'] ?? null) || abs($raw['lat']) > 90 => 'lat must be between -90 and 90.',
            ! is_numeric($raw['lng'] ?? null) || abs($raw['lng']) > 180 => 'lng must be between -180 and 180.',
            default => null,
        };
    }

    private function toPoint(array $raw): Point
    {
        $accuracy = $raw['accuracy'] ?? null;

        return new Point(
            ts: $this->timestamp($raw['recorded_at']),
            latE7: (int) round($raw['lat'] * 1e7),
            lngE7: (int) round($raw['lng'] * 1e7),
            accuracyM: is_numeric($accuracy) && $accuracy >= 0 ? (int) min(65535, round($accuracy)) : null,
        );
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (is_string($value) && strtotime($value) !== false) {
            return strtotime($value);
        }

        return null;
    }
}
