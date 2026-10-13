<?php

namespace App\Console\Commands;

use App\Models\Rep;
use App\Tracking\IngestionService;
use App\Tracking\RouteGenerator;
use Carbon\CarbonPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:generate {from : Y-m-d} {to : Y-m-d} {--rep=* : Rep ids (default: all reps)}')]
#[Description('Generate realistic working days (Monday-Friday) of GPS points for reps')]
class GenerateDays extends Command
{
    public function handle(RouteGenerator $generator, IngestionService $ingestion): void
    {
        $reps = $this->option('rep') ? Rep::findMany($this->option('rep')) : Rep::all();
        $stored = 0;

        foreach (CarbonPeriod::create($this->argument('from'), $this->argument('to')) as $day) {
            if ($day->isWeekend()) {
                continue;
            }
            foreach ($reps as $rep) {
                $date = $day->toDateString();
                foreach (array_chunk($generator->generate($date, crc32("{$rep->id}-{$date}")), 100) as $batch) {
                    $stored += $ingestion->store($rep, $batch)['stored'];
                }
            }
        }

        $this->info("Stored {$stored} points.");
    }
}
