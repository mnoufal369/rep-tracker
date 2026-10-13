<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\DaySummary;
use App\Tracking\DayTrackReader;
use App\Tracking\IngestionService;
use App\Tracking\LocalDay;
use App\Tracking\Point;
use App\Tracking\RouteGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RepDayController extends Controller
{
    public function __construct(
        private readonly DayTrackReader $reader,
    ) {}

    /** GET /reps */
    public function reps(): JsonResponse
    {
        return response()->json(['data' => Rep::orderBy('name')->get()]);
    }

    /** GET /reps/{rep}/days: summary per day, newest first (default: last 30 days). */
    public function index(Request $request, Rep $rep): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $request->input('to', LocalDay::today());
        $from = $request->input('from', CarbonImmutable::parse($to)->subDays(30)->toDateString());

        // Archived days: the summary is already stored, no need to unpack the points.
        $days = DailyTrack::where('rep_id', $rep->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->mapWithKeys(fn (DailyTrack $track) => [substr($track->date, 0, 10) => [
                'date' => substr($track->date, 0, 10),
                'archived' => true,
                'point_count' => $track->point_count,
                'distance_m' => $track->distance_m,
                'gap_count' => $track->gap_count,
            ]])
            ->all();

        // Days not archived yet (today, or a late upload): calculate the summary now.
        $pending = DB::table('pending_archive_days')->where('rep_id', $rep->id)->whereBetween('date', [$from, $to])->pluck('date');
        foreach ($pending as $date) {
            $date = substr($date, 0, 10);
            $summary = DaySummary::of($this->reader->points($rep, $date));
            $days[$date] = ['date' => $date, 'archived' => false] + array_intersect_key($summary, array_flip(['point_count', 'distance_m', 'gap_count']));
        }

        krsort($days);

        return response()->json(['rep' => $rep, 'from' => $from, 'to' => $to, 'data' => array_values($days)]);
    }

    /** GET /reps/{rep}/days/{date}: summary of one day, including its coverage gaps. */
    public function show(Rep $rep, string $date): JsonResponse
    {
        $this->validateDate($date);

        return response()->json([
            'rep' => $rep,
            'date' => $date,
            'archived' => DailyTrack::where(['rep_id' => $rep->id, 'date' => $date])->exists(),
            ...DaySummary::of($this->reader->points($rep, $date)),
        ]);
    }

    /** GET /reps/{rep}/days/{date}/points: every stored point of the day. */
    public function points(Rep $rep, string $date): JsonResponse
    {
        $this->validateDate($date);
        $points = $this->reader->points($rep, $date);

        return response()->json([
            'rep_id' => $rep->id,
            'date' => $date,
            'count' => count($points),
            'data' => array_map(fn (Point $p) => $p->toArray(), $points),
        ]);
    }

    /** POST /reps/{rep}/days/{date}/generate: create a realistic day of points for testing. */
    public function generate(Request $request, Rep $rep, string $date, RouteGenerator $generator, IngestionService $ingestion): JsonResponse
    {
        $this->validateDate($date);
        abort_if($date > LocalDay::today(), 422, 'Cannot generate a day in the future.');
        $seed = (int) $request->input('seed', crc32("{$rep->id}-{$date}"));

        $totals = ['generated' => 0, 'stored' => 0, 'duplicates' => 0];
        // Upload in batches of 100, the way a phone would.
        foreach (array_chunk($generator->generate($date, $seed), 100) as $batch) {
            $result = $ingestion->store($rep, $batch);
            $totals['generated'] += count($batch);
            $totals['stored'] += $result['stored'];
            $totals['duplicates'] += $result['duplicates'];
        }

        return response()->json(['rep_id' => $rep->id, 'date' => $date, ...$totals], 201);
    }

    private function validateDate(string $date): void
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 422, 'Date must be a valid Y-m-d date.');
    }
}
