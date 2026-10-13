<?php

namespace App\Console\Commands;

use App\Tracking\Storage\PartitionManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tracking:partitions {--months-ahead=3}')]
#[Description('Create upcoming monthly partitions for location_points and list them')]
class MaintainPartitions extends Command
{
    public function handle(PartitionManager $partitions): int
    {
        if (! $partitions->supported()) {
            $this->warn('Partitioning is only used on MySQL; nothing to do.');

            return self::SUCCESS;
        }

        $created = $partitions->ensureRange(now(), now()->addMonths((int) $this->option('months-ahead')));
        $this->info($created ? 'Created: '.implode(', ', $created) : 'All partitions already exist.');

        $this->table(['Partition', 'Holds rows before', '~Rows', 'Size'], array_map(fn ($p) => [
            $p['name'], $p['upper_bound'] ?? 'MAXVALUE', number_format($p['rows']), number_format($p['bytes'] / 1024, 1).' KB',
        ], $partitions->partitions()));

        return self::SUCCESS;
    }
}
