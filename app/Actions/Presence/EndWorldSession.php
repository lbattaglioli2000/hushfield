<?php

namespace App\Actions\Presence;

use App\Actions\World\LeaveRoom;
use App\Models\User;
use App\Models\WorldSession;

/**
 * Takes the player out of the world: leaves their room and drops the session token.
 */
final readonly class EndWorldSession
{
    public function __construct(private LeaveRoom $leaveRoom) {}

    public function __invoke(User $user, ?string $onlyToken = null): void
    {
        $session = WorldSession::query()->where('user_id', $user->id)->first();

        if ($onlyToken !== null && $session?->session_token !== $onlyToken) {
            return;
        }

        if ($user->lastRoomInstance !== null) {
            ($this->leaveRoom)($user, $user->lastRoomInstance);
        }

        $session?->delete();
    }
}
