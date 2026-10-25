<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for chat across every room. Checked with the global "world" scope.
 */
#[Name('chat-enabled')]
final class ChatEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
