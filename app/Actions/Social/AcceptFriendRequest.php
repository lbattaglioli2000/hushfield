<?php

namespace App\Actions\Social;

use App\Domain\Social\FriendNotifier;
use App\Exceptions\WorldActionRefused;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The recipient accepts: both directions of the friendship are written, and
 * every request between the two is cleared.
 */
final readonly class AcceptFriendRequest
{
    public function __construct(private FriendNotifier $notifier) {}

    public function __invoke(User $recipient, FriendRequest $request): void
    {
        if ($request->recipient_id !== $recipient->id) {
            throw WorldActionRefused::friendRequestGone();
        }

        $sender = $request->sender;
        $max = (int) config('hushfield.friends.max_friends');

        if ($recipient->friends()->count() >= $max || $sender->friends()->count() >= $max) {
            throw WorldActionRefused::friendListFull();
        }

        DB::transaction(function () use ($recipient, $sender): void {
            $now = now();

            Friendship::query()->insertOrIgnore([
                ['user_id' => $recipient->id, 'friend_id' => $sender->id, 'created_at' => $now],
                ['user_id' => $sender->id, 'friend_id' => $recipient->id, 'created_at' => $now],
            ]);

            FriendRequest::query()
                ->where(fn ($query) => $query->where('sender_id', $sender->id)->where('recipient_id', $recipient->id))
                ->orWhere(fn ($query) => $query->where('sender_id', $recipient->id)->where('recipient_id', $sender->id))
                ->delete();
        });

        $this->notifier->notify($sender, (string) __('hushfield.friendship_started', ['name' => $recipient->username]));
    }
}
