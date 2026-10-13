<?php

namespace App\Console\Commands;

use App\Tracking\PartitionManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:partitions')]
#[Description('Create the monthly partitions for the coming months (MySQL)')]
class AddPartitions extends Command
{
    public function handle(PartitionManager $partitions): void
    {
        if ($partitions->enabled()) {
            $partitions->addUpcomingMonths();
        }
        $this->info('Partitions are up to date.');
    }
}
