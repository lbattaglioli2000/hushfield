<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One direction of a friendship; every friendship is stored as two rows.
 * Written only by App\Actions\Social\AcceptFriendRequest and RemoveFriend.
 *
 * @property int $id
 * @property int $user_id
 * @property int $friend_id
 * @property Carbon $created_at
 * @property-read User $friend
 */
#[Fillable(['user_id', 'friend_id'])]
class Friendship extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }
}
