<?php

namespace App\Actions\Presence;

use App\Events\Presence\SessionSuperseded;
use App\Exceptions\WorldActionRefused;
use App\Models\User;
use App\Models\WorldSession;
use Illuminate\Support\Str;

/**
 * Makes this the one place the account is in the world from. Any earlier
 * session is told it has been superseded and its token stops working.
 */
final readonly class StartWorldSession
{
    public function __invoke(User $user): WorldSession
    {
        if ($user->isBanned()) {
            throw WorldActionRefused::banned();
        }

        $previous = $user->worldSession()->first();

        if ($previous !== null) {
            broadcast(new SessionSuperseded($previous->session_token));
        }

        // Through the relation, so user_id is set as the foreign key rather than mass-assigned.
        /** @var WorldSession $session */
        $session = $user->worldSession()->updateOrCreate(
            [],
            ['session_token' => Str::random(48), 'connected_at' => now(), 'last_seen_at' => now()],
        );

        $user->setRelation('worldSession', $session);

        return $session;
    }
}
