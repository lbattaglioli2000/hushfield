<?php

namespace App\Actions\Presence;

use App\Actions\World\LeaveRoom;
use App\Models\User;

/**
 * Takes the player out of the world: leaves their room and drops the session token.
 *
 * With $onlyToken, does nothing unless that token is still the current session,
 * so a late goodbye from an old tab cannot end a newer session.
 */
final readonly class EndWorldSession
{
    public function __construct(private LeaveRoom $leaveRoom) {}

    public function __invoke(User $user, ?string $onlyToken = null): void
    {
        $session = $user->worldSession;

        if ($onlyToken !== null && $session?->session_token !== $onlyToken) {
            return;
        }

        if ($user->lastRoomInstance !== null) {
            ($this->leaveRoom)($user, $user->lastRoomInstance);
        }

        $session?->delete();
        $user->setRelation('worldSession', null);
    }
}
