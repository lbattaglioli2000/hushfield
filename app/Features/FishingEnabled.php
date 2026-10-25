<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for fishing at the Pier: casting and reeling are both refused,
 * so no catch pays while it is off. Checked with the global "world" scope.
 */
#[Name('fishing-enabled')]
final class FishingEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
