<?php

namespace App\Actions\Social;

use App\Actions\Housing\SetHomeVisitors;
use App\Domain\Social\FriendNotifier;
use App\Models\Friendship;
use App\Models\User;

/**
 * Ends a friendship in both directions. Either one visiting the other's
 * friends-only home is shown out. The other resident is not told why their
 * list changed.
 */
final readonly class RemoveFriend
{
    public function __construct(
        private SetHomeVisitors $setHomeVisitors,
        private FriendNotifier $notifier,
    ) {}

    public function __invoke(User $user, User $friend): void
    {
        Friendship::query()
            ->where(fn ($query) => $query->where('user_id', $user->id)->where('friend_id', $friend->id))
            ->orWhere(fn ($query) => $query->where('user_id', $friend->id)->where('friend_id', $user->id))
            ->delete();

        foreach ([$user, $friend] as $owner) {
            if ($owner->home !== null) {
                $this->setHomeVisitors->showOutUnwelcome($owner->home);
            }
        }

        $this->notifier->notify($friend);
    }
}
