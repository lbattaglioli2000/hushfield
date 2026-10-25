<?php

use App\Domain\Presence\InstanceMembers;
use App\Models\RoomInstance;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

// presence-room.{instance}: only current, unbanned members of the instance.
Broadcast::channel('room.{instance}', function (User $user, RoomInstance $instance): array|false {
    if ($user->isBanned() || ! app(InstanceMembers::class)->contains($instance->id, $user->id)) {
        return false;
    }

    return [
        'id' => $user->id,
        'username' => $user->username,
        'avatar' => $user->avatar?->toPayload(),
    ];
});

// private-world-session.{token}: session notices (superseded, banned, moved).
Broadcast::channel('world-session.{token}', fn (User $user, string $token): bool => WorldSession::query()
    ->where('user_id', $user->id)
    ->where('session_token', $token)
    ->exists());
