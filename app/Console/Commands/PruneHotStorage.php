<?php

namespace App\Console\Commands;

use App\Tracking\HotStoragePruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:prune')]
#[Description('Delete raw points older than the retention period (after archiving them)')]
class PruneHotStorage extends Command
{
    public function handle(HotStoragePruner $pruner): void
    {
        $this->info('Done. Dropped '.$pruner->prune().' old monthly partition(s).');
    }
}
