<?php

namespace Database\Seeders;

use App\Models\Rep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Sanne de Vries', 'Daan Bakker', 'Fatima El Amrani', 'Lucas Janssen'] as $i => $name) {
            Rep::factory()->create(['name' => $name, 'email' => 'rep'.($i + 1).'@example.com']);
        }

        // One week, three years ago: will be archived and its raw points deleted,
        // so it is read back from the archive only.
        $oldWeek = now()->subYears(3)->startOfWeek();
        Artisan::call('tracking:generate', ['from' => $oldWeek->toDateString(), 'to' => $oldWeek->addDays(4)->toDateString(), '--rep' => [1]]);

        // The last three weeks, including today so far.
        Artisan::call('tracking:generate', ['from' => now()->subWeeks(3)->toDateString(), 'to' => now()->toDateString()]);

        Artisan::call('tracking:archive');
        Artisan::call('tracking:prune');
    }
}
