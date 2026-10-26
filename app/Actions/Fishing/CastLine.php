<?php

namespace App\Actions\Fishing;

use App\Domain\Fishing\Cast;
use App\Domain\Fishing\CastStore;
use App\Domain\Fishing\CatchTable;
use App\Domain\World\Clock;
use App\Domain\World\FixtureKind;
use App\Domain\World\FixtureReach;
use App\Features\WorldFlag;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Puts a line in the water from a fishing spot. The server rolls when it bites
 * and what is on the end now; the client only learns when to show the bite.
 * A player has one line at a time: casting again replaces the last one.
 */
final readonly class CastLine
{
    public function __construct(
        private FixtureReach $fixtures,
        private CatchTable $catches,
        private CastStore $casts,
    ) {}

    public function __invoke(User $user): Cast
    {
        WorldFlag::Fishing->ensureActive();

        $instance = $this->fixtures->ensureWithin($user, FixtureKind::Fishing);

        /** @var array{int, int} $biteAfter */
        $biteAfter = config('hushfield.fishing.bite_after_ms');
        $now = Clock::nowMs();
        $biteAt = $now + random_int(...$biteAfter);

        $cast = new Cast(
            id: (string) Str::uuid(),
            instanceId: $instance->id,
            biteAtMs: $biteAt,
            reelByMs: $biteAt + (int) config('hushfield.fishing.reel_window_ms'),
            catchKey: $this->catches->roll()->key,
        );

        $this->casts->put($user->id, $cast, $now);

        return $cast;
    }
}
