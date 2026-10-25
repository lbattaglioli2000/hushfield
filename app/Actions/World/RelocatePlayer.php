<?php

namespace App\Actions\World;

use App\Events\Presence\InstanceReassigned;
use App\Events\WorldBroadcast;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Takes a player out of an instance and tells their client where to go
 * instead; the client rejoins on its own.
 */
final readonly class RelocatePlayer
{
    public function __construct(private LeaveRoom $leaveRoom) {}

    public function __invoke(User $user, RoomInstance $from, string $message, ?RoomInstance $target = null, ?string $roomSlug = null): void
    {
        ($this->leaveRoom)($user, $from);

        $token = $user->worldSession?->session_token;

        if ($token !== null) {
            WorldBroadcast::send(new InstanceReassigned($token, $message, $target?->id, $roomSlug));
        }
    }
}
