<?php

namespace App\Models;

use Database\Factories\RepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Rep extends Model
{
    /** @use HasFactory<RepFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'device_token_hash'];

    protected $hidden = ['device_token_hash', 'created_at', 'updated_at'];

    public static function findByDeviceToken(string $token): ?self
    {
        return static::where('device_token_hash', hash('sha256', $token))->first();
    }

    /** Creates a new token for the rep's phone. Only its hash is saved. */
    public function issueDeviceToken(): string
    {
        $token = Str::random(48);
        $this->update(['device_token_hash' => hash('sha256', $token)]);

        return $token;
    }
}
