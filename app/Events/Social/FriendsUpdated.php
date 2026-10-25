<?php

namespace App\Events\Social;

use App\Events\Presence\SessionEvent;

/**
 * Something changed in the player's friends or requests. The client reloads
 * its friends list and shows the message, when there is one.
 */
final class FriendsUpdated extends SessionEvent
{
    public function __construct(string $sessionToken, public readonly ?string $message = null)
    {
        parent::__construct($sessionToken);
    }

    protected function payload(): array
    {
        return ['message' => $this->message];
    }
}
