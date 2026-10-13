<?php

namespace App\Models;

use App\Tracking\Point;
use App\Tracking\TrackCodec;
use Illuminate\Database\Eloquent\Model;

class DailyTrack extends Model
{
    protected $guarded = [];

    protected $hidden = ['points_blob'];

    /** @return list<Point> */
    public function points(): array
    {
        return TrackCodec::decode($this->points_blob);
    }
}
