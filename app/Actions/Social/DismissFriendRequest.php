<?php

namespace App\Actions\Social;

use App\Domain\Social\FriendNotifier;
use App\Exceptions\WorldActionRefused;
use App\Models\FriendRequest;
use App\Models\User;

/**
 * The recipient declines a request, or the sender withdraws it. A declined
 * sender is not told; their request simply stops waiting.
 */
final readonly class DismissFriendRequest
{
    public function __construct(private FriendNotifier $notifier) {}

    public function __invoke(User $by, FriendRequest $request): void
    {
        if ($request->sender_id !== $by->id && $request->recipient_id !== $by->id) {
            throw WorldActionRefused::friendRequestGone();
        }

        $request->delete();

        // A withdrawn request disappears from the recipient's list straight away.
        if ($request->sender_id === $by->id) {
            $this->notifier->notify($request->recipient);
        }
    }
}
