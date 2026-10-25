<?php

namespace App\Actions\Presence;

use App\Models\WorldSession;

/**
 * Removes players whose connection died without a goodbye.
 */
final readonly class SweepGhosts
{
    public function __construct(private EndWorldSession $endWorldSession) {}

    /**
     * @return int How many sessions were swept.
     */
    public function __invoke(): int
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
}
