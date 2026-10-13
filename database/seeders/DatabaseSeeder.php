<?php

namespace Database\Seeders;

use App\Models\Rep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Four reps with three weeks of simulated shifts (plus today so far, if the shift has
     * started) and an old week that only exists in the archive.
     */
    public function run(): void
    {
        $reps = [
            ['Sanne de Vries', 'sanne@example.com'],
            ['Daan Bakker', 'daan@example.com'],
            ['Fatima El Amrani', 'fatima@example.com'],
            ['Lucas Janssen', 'lucas@example.com'],
        ];

        foreach ($reps as [$name, $email]) {
            Rep::factory()->create(['name' => $name, 'email' => $email]);
        }

        $output = $this->command->getOutput();

        // A week from three years ago: archived, then its raw partitions are dropped, so it is
        // served from cold storage only, exactly like real history would be.
        $oldWeek = now()->subYears(3)->startOfWeek();
        Artisan::call('tracking:simulate', ['--rep' => [1, 2], '--from' => $oldWeek->toDateString(), '--to' => $oldWeek->addDays(4)->toDateString()], $output);

        // The last three weeks, archived but still in hot storage.
        Artisan::call('tracking:simulate', ['--from' => now()->subDays(21)->toDateString(), '--to' => now()->subDay()->toDateString()], $output);

        Artisan::call('tracking:archive', [], $output);
        Artisan::call('tracking:prune', [], $output);

        // Today stays "live" (raw only), like a shift in progress.
        if (now(config('tracking.default_timezone'))->hour >= 10) {
            Artisan::call('tracking:simulate', [], $output);
        }
    }
}
