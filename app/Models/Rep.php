<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Rep extends Model
{
    /** @use HasFactory<RepFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'timezone', 'device_token_hash'];

    protected $hidden = ['device_token_hash'];

    public function dailyTracks(): HasMany
    {
        return $this->hasMany(DailyTrack::class);
    }

    public static function findByDeviceToken(string $token): ?self
    {
        return static::where('device_token_hash', hash('sha256', $token))->first();
    }

    /** Issues a new device token; only its hash is stored. */
    public function issueDeviceToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['device_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /**
     * UTC [start, end) of a local calendar day for this rep.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayBounds(string $date): array
    {
        $start = CarbonImmutable::parse($date, $this->timezone)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function localDate(int $ts): string
    {
        return CarbonImmutable::createFromTimestamp($ts, $this->timezone)->toDateString();
    }
}
