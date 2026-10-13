<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The archive: one row per rep per day, kept for years.
 *
 * `points_blob` holds every point of the day, compressed without losing anything
 * (see TrackCodec). The other columns are a ready-made summary, so listing old days
 * never needs to unpack the blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rep_id')->constrained('reps')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('point_count');
            $table->dateTime('first_recorded_at');
            $table->dateTime('last_recorded_at');
            $table->unsignedInteger('distance_m');
            $table->unsignedSmallInteger('gap_count');
            $table->char('checksum', 64);
            $table->rawColumn('points_blob', 'mediumblob');
            $table->timestamps();

            $table->unique(['rep_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_tracks');
    }
};
