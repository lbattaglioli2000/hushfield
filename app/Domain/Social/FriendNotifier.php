<?php

namespace App\Domain\Social;

use App\Events\Social\FriendsUpdated;
use App\Events\WorldBroadcast;
use App\Models\User;

/**
 * Tells a player in the world that their friends changed. Players who are not
 * in the world see the change the next time they open their friends list.
 */
final class FriendNotifier
{
    public function notify(User $user, ?string $message = null): void
    {
        $token = $user->worldSession()->value('session_token');

        if (is_string($token)) {
            WorldBroadcast::send(new FriendsUpdated($token, $message));
        }
    }
}
