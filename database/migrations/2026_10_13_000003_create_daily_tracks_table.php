<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold storage: one row per rep per local day.
 *
 * `points_blob` is a lossless, delta + varint encoded, deflated copy of every raw fix of
 * the day (see TrackCodec). The remaining columns are pre-computed so listing years of
 * history never has to decode a blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rep_id')->constrained('reps')->cascadeOnDelete();
            $table->date('date');                       // local date in the rep's timezone
            $table->unsignedInteger('point_count');
            $table->dateTime('first_recorded_at');
            $table->dateTime('last_recorded_at');
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('moving_seconds');
            $table->unsignedSmallInteger('stop_count');
            $table->unsignedSmallInteger('gap_count');
            $table->unsignedInteger('longest_gap_seconds');
            $table->integer('min_lat_e7');
            $table->integer('min_lng_e7');
            $table->integer('max_lat_e7');
            $table->integer('max_lng_e7');
            $table->unsignedTinyInteger('codec_version');
            $table->char('checksum', 64);               // sha256 of the canonical raw points
            $table->rawColumn('points_blob', 'mediumblob');   // up to 16 MB
            $table->timestamps();

            $table->unique(['rep_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_tracks');
    }
};
