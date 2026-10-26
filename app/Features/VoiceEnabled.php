<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for proximity voice: no new voice connections are handed out
 * while it is off. Checked with the global "world" scope.
 */
#[Name('voice-enabled')]
final class VoiceEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
