<?php

use App\Tracking\PartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw GPS points of the last 90 days.
 *
 * - Coordinates are stored as whole numbers (degrees x 10,000,000). That is 4 bytes instead
 *   of 8 for a DOUBLE and still precise to about 1 cm.
 * - The primary key (rep_id, recorded_at) keeps one rep's points next to each other on disk,
 *   so reading a day is fast, and it makes a re-sent point a harmless duplicate.
 * - On MySQL the table is split into one partition per month, so an old month can be
 *   removed instantly with DROP PARTITION.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_points', function (Blueprint $table) {
            $table->unsignedInteger('rep_id');
            $table->dateTime('recorded_at');                      // UTC
            $table->integer('lat_e7');
            $table->integer('lng_e7');
            $table->unsignedSmallInteger('accuracy_m')->nullable();

            $table->primary(['rep_id', 'recorded_at']);
        });

        app(PartitionManager::class)->createPartitions();
    }

    public function down(): void
    {
        Schema::dropIfExists('location_points');
    }
};
