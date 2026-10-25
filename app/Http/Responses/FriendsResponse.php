<?php

namespace App\Http\Responses;

use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * A resident's friends (online first, with where they are and whether the
 * resident may follow), their requests both ways, and their request setting:
 * the answer to every friends request, so the client redraws from one shape.
 */
final class FriendsResponse
{
    public function for(User $user, ?string $message = null, int $status = 200): JsonResponse
    {
        $onlineSince = now()->subSeconds((int) config('hushfield.presence.ghost_after_seconds'));

        $friends = $user->friends()
            ->with(['worldSession', 'lastRoomInstance.room', 'lastRoomInstance.home.owner'])
            ->orderBy('username')
            ->get()
            ->map(function (User $friend) use ($user, $onlineSince): array {
                $online = $friend->worldSession !== null && $friend->worldSession->last_seen_at->gte($onlineSince);
                $instance = $online ? $friend->lastRoomInstance : null;

                return [
                    'id' => $friend->id,
                    'username' => $friend->username,
                    'online' => $online,
                    'location' => $instance === null ? null : [
                        'room' => $instance->room->slug,
                        'instance_id' => $instance->id,
                        'label' => $instance->label(),
                        'can_join' => $instance->room->is_active
                            && (! $instance->room->isResidence() || ($instance->home?->admits($user) ?? false)),
                    ],
                ];
            })
            ->sortByDesc('online')
            ->values()
            ->all();

        return WorldResponse::json([
            'message' => $message,
            'friends' => [
                'friends' => $friends,
                'incoming' => $user->incomingFriendRequests()->with('sender')->latest('id')->get()
                    ->map(fn (FriendRequest $request): array => ['id' => $request->id, 'user' => ['id' => $request->sender_id, 'username' => $request->sender->username]])
                    ->all(),
                'outgoing' => $user->outgoingFriendRequests()->with('recipient')->latest('id')->get()
                    ->map(fn (FriendRequest $request): array => ['id' => $request->id, 'user' => ['id' => $request->recipient_id, 'username' => $request->recipient->username]])
                    ->all(),
                'requests_allowed' => $user->friend_requests_allowed,
            ],
        ], $status);
    }
}
