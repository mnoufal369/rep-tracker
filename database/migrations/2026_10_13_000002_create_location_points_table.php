<?php

use App\Tracking\Storage\PartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hot storage for raw GPS fixes.
 *
 * Every column is sized for the data it holds so a row stays ~25 bytes of payload:
 *  - coordinates are stored as INT micro-degrees x10 (1e-7°, ~1 cm) instead of DOUBLE
 *  - the primary key (rep_id, recorded_at) is the clustered index, so reading one rep's day
 *    is a single contiguous range scan and a re-sent fix is naturally de-duplicated
 *  - on MySQL the table is RANGE-partitioned by month, so old data is removed with an
 *    instant DROP PARTITION instead of a huge DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_points', function (Blueprint $table) {
            $table->unsignedInteger('rep_id');
            $table->dateTime('recorded_at');          // UTC, second precision
            $table->integer('lat_e7');
            $table->integer('lng_e7');
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedSmallInteger('speed_cms')->nullable();   // cm/s
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();

            $table->primary(['rep_id', 'recorded_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            app(PartitionManager::class)->partitionTable(now()->subMonths(1), now()->addMonths(3));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_points');
    }
};
