<?php

namespace App\Console\Commands;

use App\Tracking\Storage\HotStoragePruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:prune {--dry-run : Only show what would be removed}')]
#[Description('Drop raw fixes older than the hot retention window, after verifying they are archived')]
class PruneHotStorage extends Command
{
    public function handle(HotStoragePruner $pruner): int
    {
        $actions = $pruner->prune((bool) $this->option('dry-run'));

        foreach ($actions as $action) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '').$action);
        }
        $this->info($actions ? 'Done.' : 'Nothing older than '.config('tracking.hot_retention_days').' days.');

        return self::SUCCESS;
    }
}
