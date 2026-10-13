<?php

namespace Database\Factories;

use App\Models\Rep;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Rep> */
class RepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'device_token_hash' => hash('sha256', Str::random(48)),
        ];
    }
}
