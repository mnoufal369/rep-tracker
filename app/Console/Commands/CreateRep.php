<?php

namespace App\Console\Commands;

use App\Models\Rep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('tracking:create-rep {name} {email} {--timezone=}')]
#[Description('Register a sales rep and print the device token for their phone')]
class CreateRep extends Command
{
    public function handle(): int
    {
        $rep = Rep::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'timezone' => $this->option('timezone') ?? config('tracking.default_timezone'),
            'device_token_hash' => hash('sha256', $token = Str::random(48)),
        ]);

        $this->info("Rep #{$rep->id} created. Device token (shown once):");
        $this->line($token);

        return self::SUCCESS;
    }
}
