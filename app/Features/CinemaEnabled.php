<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for the VFA's screen: while it is off the programme is withheld
 * and every screen goes dark at once. Checked with the global "world" scope.
 */
#[Name('cinema-enabled')]
final class CinemaEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
