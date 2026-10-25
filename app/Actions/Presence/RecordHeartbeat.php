<?php

namespace App\Actions\Presence;

use App\Models\WorldSession;

final readonly class RecordHeartbeat
{
    public function __invoke(WorldSession $session): void
    {
        $session->forceFill(['last_seen_at' => now()])->save();
    }
}
