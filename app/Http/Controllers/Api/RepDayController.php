<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rep;
use App\Tracking\DayReport;
use App\Tracking\Point;
use App\Tracking\Simulation\DeviceUploadSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepDayController extends Controller
{
    public function __construct(
        private readonly DayReport $report,
    ) {}

    public function reps(): JsonResponse
    {
        return response()->json(['data' => Rep::orderBy('name')->get()->map(fn (Rep $r) => $this->report->rep($r))]);
    }

    public function index(Request $request, Rep $rep): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $validated['to'] ?? CarbonImmutable::today($rep->timezone)->toDateString();
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->subDays(30)->toDateString();

        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366, 422, 'The range may span at most one year.');

        return response()->json([
            'rep' => $this->report->rep($rep),
            'from' => $from,
            'to' => $to,
            'data' => $this->report->days($rep, $from, $to),
        ]);
    }

    public function show(Rep $rep, string $date): JsonResponse
    {
        return response()->json($this->report->day($rep, $this->date($date)));
    }

    public function track(Request $request, Rep $rep, string $date): JsonResponse
    {
        $tolerance = (float) ($request->validate([
            'simplify' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ])['simplify'] ?? config('tracking.map_simplify_tolerance_m'));

        return response()->json($this->report->geoJson($rep, $this->date($date), $tolerance));
    }

    /** Full-fidelity export of every stored fix, as JSON or CSV. */
    public function points(Request $request, Rep $rep, string $date): JsonResponse|StreamedResponse
    {
        $points = $this->report->points($rep, $this->date($date));

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($points) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['recorded_at', 'lat', 'lng', 'accuracy', 'speed', 'heading', 'battery'], escape: '');
                foreach ($points as $p) {
                    fputcsv($out, $p->toApi(), escape: '');
                }
                fclose($out);
            }, "rep-{$rep->id}-{$date}.csv", ['Content-Type' => 'text/csv']);
        }

        return response()->json([
            'rep_id' => $rep->id,
            'date' => $date,
            'count' => count($points),
            'data' => array_map(fn (Point $p) => $p->toApi(), $points),
        ]);
    }

    public function simulate(Request $request, Rep $rep, string $date, DeviceUploadSimulator $simulator): JsonResponse
    {
        abort_unless(config('tracking.simulator_enabled'), 404);
        $date = $this->date($date);
        abort_if($date > CarbonImmutable::today($rep->timezone)->toDateString(), 422, 'Cannot simulate a day in the future.');

        $seed = $request->validate(['seed' => ['nullable', 'integer']])['seed'] ?? null;

        return response()->json($simulator->simulate($rep, $date, $seed), 201);
    }

    private function date(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 422, 'Date must be a valid Y-m-d date.');

        return $date;
    }
}
