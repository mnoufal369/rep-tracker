<?php

namespace App\Console\Commands;

use App\Models\DailyTrack;
use App\Models\Rep;
use App\Tracking\DayArchiver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:archive {--rep= : Archive one rep-day (requires --date)} {--date= : Y-m-d} {--rebuild : Re-archive every archived day, e.g. after changing the timeline algorithm}')]
#[Description('Compact finished rep-days from raw fixes into verified daily archives')]
class ArchiveDays extends Command
{
    public function handle(DayArchiver $archiver): int
    {
        if ($this->option('rep') || $this->option('date')) {
            $rep = Rep::findOrFail($this->option('rep'));
            $track = $archiver->archive($rep, $this->option('date'));
            $this->info($track ? "Archived {$track->point_count} points." : 'No data for that day.');

            return self::SUCCESS;
        }

        if ($this->option('rebuild')) {
            $count = 0;
            DailyTrack::select(['id', 'rep_id', 'date'])->with('rep')->lazyById(200)->each(function (DailyTrack $t) use ($archiver, &$count) {
                $archiver->archive($t->rep, substr((string) $t->date, 0, 10));
                $count++;
            });
            $this->info("Rebuilt {$count} archived rep-day(s).");
        }

        $count = $archiver->archivePending(fn (Rep $rep, string $date) => $this->line("  archived rep {$rep->id} {$date}"));
        $this->info("Archived {$count} rep-day(s).");

        return self::SUCCESS;
    }
}
