<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for new room joins. Players already in a room stay. Checked with the global "world" scope.
 */
#[Name('joins-enabled')]
final class JoinsEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
