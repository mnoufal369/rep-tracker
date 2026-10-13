<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // sha256 of the token the rep's phone sends. The token itself is never stored.
            $table->char('device_token_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reps');
    }
};
