<?php

namespace App\Models;

use App\Tracking\Point;
use App\Tracking\TrackCodec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyTrack extends Model
{
    protected $guarded = [];

    protected $hidden = ['points_blob'];

    protected function casts(): array
    {
        return [
            'first_recorded_at' => 'datetime',
            'last_recorded_at' => 'datetime',
        ];
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(Rep::class);
    }

    /** @return list<Point> */
    public function points(): array
    {
        $blob = $this->points_blob;
        if (is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }

        return TrackCodec::decode($blob, $this->codec_version);
    }
}
