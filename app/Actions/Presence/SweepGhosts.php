<?php

namespace App\Actions\Presence;

use App\Actions\World\LeaveRoom;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerLeft;
use App\Events\WorldBroadcast;
use App\Models\RoomInstance;
use App\Models\User;
use App\Models\WorldSession;

/**
 * Removes players whose connection died without a goodbye, then reconciles
 * Redis occupancy with Postgres: anyone in an instance without a world
 * session (a deleted account, an interrupted request) is removed too.
 */
final readonly class SweepGhosts
{
    public function __construct(
        private EndWorldSession $endWorldSession,
        private LeaveRoom $leaveRoom,
        private InstanceMembers $members,
        private PositionStore $positions,
    ) {}

    /**
     * @return int How many players were swept.
     */
    public function __invoke(): int
    {
        return $this->sweepStaleSessions() + $this->sweepMembersWithoutSessions();
    }

    private function sweepStaleSessions(): int
    {
        $swept = 0;

        WorldSession::query()
            ->with('user.lastRoomInstance')
            ->where('last_seen_at', '<', now()->subSeconds((int) config('hushfield.presence.ghost_after_seconds')))
            ->each(function (WorldSession $session) use (&$swept): void {
                $session->user->setRelation('worldSession', $session);
                ($this->endWorldSession)($session->user, $session->session_token);
                $swept++;
            });

        return $swept;
    }

    private function sweepMembersWithoutSessions(): int
    {
        $swept = 0;

        foreach (RoomInstance::query()->get() as $instance) {
            $memberIds = $this->members->all($instance->id);
            $withSession = WorldSession::query()->whereIn('user_id', $memberIds)->pluck('user_id')->all();
            $orphanIds = array_values(array_diff($memberIds, $withSession));
            $users = User::query()->whereIn('id', $orphanIds)->get()->keyBy('id');

            foreach ($orphanIds as $userId) {
                $user = $users->get($userId);

                if ($user !== null) {
                    ($this->leaveRoom)($user, $instance);
                } else {
                    // The account no longer exists, so there is no user row to update.
                    $this->members->remove($instance->id, $userId);
                    $this->positions->forget($userId);
                    WorldBroadcast::send(new PlayerLeft($instance->id, $userId));
                }

                $swept++;
            }
        }

        return $swept;
    }
}
