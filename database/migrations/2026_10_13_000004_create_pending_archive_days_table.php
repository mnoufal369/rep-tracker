<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days that received new points and still need to be (re)archived.
 *
 * A row is added in the same transaction as the points, so raw data can never exist
 * without either an up-to-date archive or a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_archive_days', function (Blueprint $table) {
            $table->unsignedInteger('rep_id');
            $table->date('date');

            $table->primary(['rep_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_archive_days');
    }
};
