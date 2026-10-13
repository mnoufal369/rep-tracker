<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Models\Rep;
use Illuminate\Support\Facades\DB;

/**
 * Write path for device uploads.
 *
 * Idempotent by design: the primary key (rep_id, recorded_at) means a batch that is re-sent
 * after a timeout is simply absorbed as duplicates, so the device can retry until it gets a
 * 2xx without ever creating double data. Each valid fix is either stored or reported back as
 * a duplicate; only fixes that can never be valid are rejected, with a reason per index.
 */
final class IngestionService
{
    private const INSERT_CHUNK = 500;

    /**
     * @param  list<array<string, mixed>>  $rawPoints
     */
    public function ingest(Rep $rep, array $rawPoints): IngestResult
    {
        $rejected = [];
        $points = [];

        foreach ($rawPoints as $index => $raw) {
            $result = $this->normalize($raw);
            if (is_string($result)) {
                $rejected[] = ['index' => $index, 'reason' => $result];

                continue;
            }
            // Same second sent twice in one batch: keep the first.
            $points[$result->ts] ??= $result;
        }

        $days = array_values(array_unique(array_map(fn (Point $p) => $rep->localDate($p->ts), $points)));
        sort($days);

        $stored = DB::transaction(function () use ($rep, $points, $days) {
            $stored = 0;
            foreach (array_chunk($points, self::INSERT_CHUNK) as $chunk) {
                $stored += DB::table('location_points')->insertOrIgnore(
                    array_map(fn (Point $p) => $p->toRow($rep->id), $chunk),
                );
            }

            // Same transaction: raw data is never committed without its archive marker.
            DB::table('pending_archive_days')->insertOrIgnore(
                array_map(fn (string $day) => ['rep_id' => $rep->id, 'date' => $day], $days),
            );

            return $stored;
        });

        return new IngestResult(
            received: count($rawPoints),
            stored: $stored,
            duplicates: count($rawPoints) - count($rejected) - $stored,
            rejected: $rejected,
            days: $days,
        );
    }

    /** @return Point|string the point, or the reason it is rejected */
    private function normalize(mixed $raw): Point|string
    {
        if (! is_array($raw)) {
            return 'Point must be an object.';
        }

        $ts = $this->timestamp($raw['recorded_at'] ?? null);
        if ($ts === null) {
            return 'recorded_at must be an ISO-8601 date or unix timestamp.';
        }
        if ($ts > time() + config('tracking.max_future_skew_seconds')) {
            return 'recorded_at is in the future.';
        }
        if ($ts < 946684800) {
            return 'recorded_at is before 2000-01-01.';
        }

        $lat = $raw['lat'] ?? null;
        $lng = $raw['lng'] ?? null;
        if (! is_numeric($lat) || $lat < -90 || $lat > 90) {
            return 'lat must be between -90 and 90.';
        }
        if (! is_numeric($lng) || $lng < -180 || $lng > 180) {
            return 'lng must be between -180 and 180.';
        }

        $optional = fn (string $key, float $scale, int $max) => isset($raw[$key]) && is_numeric($raw[$key]) && $raw[$key] >= 0
            ? (int) min($max, round($raw[$key] * $scale))
            : null;

        return new Point(
            ts: $ts,
            latE7: (int) round($lat * 1e7),
            lngE7: (int) round($lng * 1e7),
            accuracyM: $optional('accuracy', 1, 65535),
            speedCms: $optional('speed', 100, 65535),
            headingDeg: isset($raw['heading']) && is_numeric($raw['heading']) ? ((int) round($raw['heading'])) % 360 : null,
            batteryPct: $optional('battery', 1, 100),
        );
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) floor($value);
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }
}
