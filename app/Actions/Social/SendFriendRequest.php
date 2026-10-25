<?php

namespace App\Actions\Social;

use App\Domain\Social\FriendNotifier;
use App\Exceptions\WorldActionRefused;
use App\Models\FriendRequest;
use App\Models\User;

/**
 * Asks another resident to be friends. When they had already asked the
 * sender, this accepts their request instead. Sending again is harmless.
 */
final readonly class SendFriendRequest
{
    public function __construct(
        private AcceptFriendRequest $acceptFriendRequest,
        private FriendNotifier $notifier,
    ) {}

    /**
     * @return bool Whether the two are now friends (the recipient had asked first).
     */
    public function __invoke(User $sender, User $recipient): bool
    {
        if ($sender->is($recipient)) {
            throw WorldActionRefused::friendSelf();
        }

        if ($sender->isFriendsWith($recipient)) {
            throw WorldActionRefused::friendAlready();
        }

        $theirs = FriendRequest::query()->where('sender_id', $recipient->id)->where('recipient_id', $sender->id)->first();

        if ($theirs !== null) {
            ($this->acceptFriendRequest)($sender, $theirs);

            return true;
        }

        if (! $this->accepts($recipient, $sender)) {
            throw WorldActionRefused::friendRequestsClosed();
        }

        if ($sender->friends()->count() >= (int) config('hushfield.friends.max_friends')) {
            throw WorldActionRefused::friendListFull();
        }

        if ($sender->outgoingFriendRequests()->count() >= (int) config('hushfield.friends.max_pending_requests')) {
            throw WorldActionRefused::friendTooManyRequests();
        }

        $request = FriendRequest::query()->firstOrCreate(['sender_id' => $sender->id, 'recipient_id' => $recipient->id]);

        if ($request->wasRecentlyCreated) {
            $this->notifier->notify($recipient, (string) __('hushfield.friend_request_received', ['name' => $sender->username]));
        }

        return false;
    }

    private function accepts(User $recipient, User $sender): bool
    {
        $sameBracket = $recipient->is_minor === $sender->is_minor;

        return $recipient->friend_requests_allowed
            && ($sameBracket || ! config('hushfield.friends.same_age_bracket_only'));
    }
}
