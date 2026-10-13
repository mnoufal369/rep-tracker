<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work queue of rep-days that received data and still need to be (re)archived.
 *
 * Written in the same transaction as the raw points, so a day can never have raw data
 * without either a marker here or an up-to-date archive. This is what makes it safe to
 * drop raw partitions later, and it covers devices that upload days late.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_archive_days', function (Blueprint $table) {
            $table->unsignedInteger('rep_id');
            $table->date('date');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['rep_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_archive_days');
    }
};
