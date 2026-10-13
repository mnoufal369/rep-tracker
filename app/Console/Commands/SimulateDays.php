<?php

namespace App\Console\Commands;

use App\Models\Rep;
use App\Tracking\Simulation\DeviceUploadSimulator;
use App\Tracking\Storage\PartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:simulate {--rep=* : Rep id(s), default all} {--from= : Y-m-d, default today} {--to= : Y-m-d, default today} {--weekends : Also simulate Saturdays and Sundays}')]
#[Description('Generate realistic shifts and upload them through the ingestion pipeline')]
class SimulateDays extends Command
{
    public function handle(DeviceUploadSimulator $simulator, PartitionManager $partitions): int
    {
        $reps = $this->option('rep') ? Rep::findMany($this->option('rep')) : Rep::all();
        $from = CarbonImmutable::parse($this->option('from') ?? 'today');
        $to = CarbonImmutable::parse($this->option('to') ?? 'today');

        if ($partitions->supported()) {
            $partitions->ensureRange($from->subMonth(), now()->addMonths(3));
        }

        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            if ($this->option('weekends') || ! $d->isWeekend()) {
                $days[] = $d->toDateString();
            }
        }

        $bar = $this->output->createProgressBar(count($days) * $reps->count());
        $points = 0;
        foreach ($reps as $rep) {
            foreach ($days as $date) {
                $points += $simulator->simulate($rep, $date)['upload']['stored'];
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine();
        $this->info("Stored {$points} points for {$reps->count()} rep(s) over ".count($days).' day(s).');

        return self::SUCCESS;
    }
}
