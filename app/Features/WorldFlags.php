<?php

namespace App\Features;

use Laravel\Pennant\Feature;

/**
 * Global (not per-user) feature flags. Pennant has no null scope for class
 * features, so global flags share one fixed scope.
 */
final class WorldFlags
{
    public const string SCOPE = 'world';

    /** @var list<class-string> */
    public const array FLAGS = [ChatEnabled::class, JoinsEnabled::class];

    public static function active(string $feature): bool
    {
        return Feature::for(self::SCOPE)->active($feature);
    }

    public static function set(string $feature, bool $active): void
    {
        $active
            ? Feature::for(self::SCOPE)->activate($feature)
            : Feature::for(self::SCOPE)->deactivate($feature);
    }
}
