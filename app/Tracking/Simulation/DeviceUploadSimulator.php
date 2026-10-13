<?php

declare(strict_types=1);

namespace App\Tracking\Simulation;

use App\Models\Rep;
use App\Tracking\IngestionService;
use App\Tracking\Point;

/**
 * Replays a simulated shift through the real ingestion path the way a phone would:
 * a batch every few minutes, a stretch without network where fixes pile up in the local
 * buffer and are flushed later in one go, and a retried batch (as if the first response
 * was lost). Proves the write path ends up with exactly the generated data.
 */
final class DeviceUploadSimulator
{
    public function __construct(
        private readonly RouteSimulator $routes,
        private readonly IngestionService $ingestion,
    ) {}

    /** @return array<string, mixed> */
    public function simulate(Rep $rep, string $date, ?int $seed = null): array
    {
        ['district' => $district, 'points' => $points] = $this->routes->generate($rep, $date, $seed);
        // Simulating today: only the part of the shift that has already happened.
        $points = array_values(array_filter($points, fn (Point $p) => $p->ts <= time()));

        $batches = array_chunk(array_map(fn (Point $p) => $p->toApi(), $points), 50);

        // Network outage: a run of batches is held back and uploaded at the end of the shift.
        $offlineFrom = (int) (count($batches) * 0.6);
        $offline = array_splice($batches, $offlineFrom, min(40, (int) (count($batches) * 0.1)));
        $batches[] = array_merge(...$offline ?: [[]]);

        // A batch whose response got lost is sent twice.
        if (count($batches) > 3) {
            array_splice($batches, 3, 0, [$batches[2]]);
        }

        $totals = ['requests' => 0, 'received' => 0, 'stored' => 0, 'duplicates' => 0, 'rejected' => 0];
        foreach (array_filter($batches) as $batch) {
            $result = $this->ingestion->ingest($rep, $batch);
            $totals['requests']++;
            $totals['received'] += $result->received;
            $totals['stored'] += $result->stored;
            $totals['duplicates'] += $result->duplicates;
            $totals['rejected'] += count($result->rejected);
        }

        return [
            'rep_id' => $rep->id,
            'date' => $date,
            'district' => $district,
            'generated_points' => count($points),
            'offline_points_flushed_late' => array_sum(array_map('count', $offline)),
            'upload' => $totals,
        ];
    }
}
