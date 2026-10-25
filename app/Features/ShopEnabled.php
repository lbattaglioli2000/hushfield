<?php

namespace App\Features;

use Laravel\Pennant\Attributes\Name;

/**
 * Kill switch for buying from the shop. Owned items, decorating and the daily
 * stipend are unaffected. Checked with the global "world" scope.
 */
#[Name('shop-enabled')]
final class ShopEnabled
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
