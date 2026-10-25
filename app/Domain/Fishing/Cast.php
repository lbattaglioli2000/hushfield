<?php

namespace App\Domain\Fishing;

/**
 * A line in the water. Everything that matters is rolled when it goes in:
 * when it bites, how long the bite lasts, and what is on the end.
 */
final readonly class Cast
{
    public function __construct(
        public string $id,
        public int $instanceId,
        public int $biteAtMs,
        public int $reelByMs,
        public string $catchKey,
    ) {}

    public function judge(int $reeledAtMs): ReelOutcome
    {
        return match (true) {
            $reeledAtMs < $this->biteAtMs => ReelOutcome::Early,
            $reeledAtMs > $this->reelByMs => ReelOutcome::Late,
            default => ReelOutcome::Landed,
        };
    }

    /**
     * What the client is told: when to show the bite, and until when a reel lands it. Never the catch.
     *
     * @return array{id: string, bite_at_ms: int, reel_by_ms: int}
     */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'bite_at_ms' => $this->biteAtMs, 'reel_by_ms' => $this->reelByMs];
    }

    /**
     * @return array{id: string, instance_id: int, bite_at_ms: int, reel_by_ms: int, catch: string}
     */
    public function toArray(): array
    {
        return [...$this->toPayload(), 'instance_id' => $this->instanceId, 'catch' => $this->catchKey];
    }

    /**
     * @param  array{id: string, instance_id: int, bite_at_ms: int, reel_by_ms: int, catch: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['instance_id'], $data['bite_at_ms'], $data['reel_by_ms'], $data['catch']);
    }
}
