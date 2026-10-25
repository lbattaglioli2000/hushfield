<?php

use App\Domain\Presence\InstanceMembers;
use App\Models\RoomInstance;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Broadcast;

// presence-room.{instance} (RoomInstance::channelNameFor): only current, unbanned members of the instance.
Broadcast::channel('room.{instance}', function (User $user, RoomInstance $instance): array|false {
    if ($user->isBanned() || ! app(InstanceMembers::class)->contains($instance->id, $user->id)) {
        return false;
    }

    return $user->toPlayerPayload();
});

// private-world-session.{token}: session notices (superseded, banned, moved).
Broadcast::channel('world-session.{token}', fn (User $user, string $token): bool => WorldSession::query()
    ->where('user_id', $user->id)
    ->where('session_token', $token)
    ->exists());
