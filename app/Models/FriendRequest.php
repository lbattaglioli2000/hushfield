<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending friend request. Accepted or dismissed requests are deleted.
 *
 * @property int $id
 * @property int $sender_id
 * @property int $recipient_id
 * @property Carbon $created_at
 * @property-read User $sender
 * @property-read User $recipient
 */
#[Fillable(['sender_id', 'recipient_id'])]
class FriendRequest extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
