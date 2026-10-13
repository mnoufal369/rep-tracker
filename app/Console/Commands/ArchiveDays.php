<?php

namespace App\Console\Commands;

use App\Tracking\DayArchiver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:archive')]
#[Description('Archive every finished day that received new points')]
class ArchiveDays extends Command
{
    public function handle(DayArchiver $archiver): void
    {
        $this->info('Archived '.$archiver->archivePending().' day(s).');
    }
}
