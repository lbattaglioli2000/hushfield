<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The one place an account is currently in the world from.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_token
 * @property Carbon $connected_at
 * @property Carbon $last_seen_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'session_token', 'connected_at', 'last_seen_at'])]
class WorldSession extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function channelNameFor(string $token): string
    {
        return "world-session.{$token}";
    }
}
