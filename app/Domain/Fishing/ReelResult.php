<?php

namespace App\Domain\Fishing;

/**
 * The answer to a reel: how it went, what came up, and what it paid.
 * Kept for a while after the reel, so a replayed request gets the same answer.
 */
final readonly class ReelResult
{
    public function __construct(
        public ReelOutcome $outcome,
        public ?FishingCatch $catch,
        public int $paid,
    ) {}

    public function message(): string
    {
        if ($this->outcome !== ReelOutcome::Landed || $this->catch === null) {
            return (string) __("hushfield.fishing_{$this->outcome->value}");
        }

        if ($this->paid === 0) {
            return (string) __('hushfield.fishing_capped');
        }

        $landed = trans_choice('hushfield.fishing_landed', $this->paid, ['coins' => $this->paid]);

        // The cap cut this one short: say so, so the next one paying nothing is no surprise.
        return $this->paid < $this->catch->coins ? $landed.' '.__('hushfield.fishing_cap_reached') : $landed;
    }

    /**
     * @return array{outcome: string, catch: array{key: string, name: string, description: string, rarity: string, coins: int}|null, paid: int}
     */
    public function toPayload(): array
    {
        return ['outcome' => $this->outcome->value, 'catch' => $this->catch?->toPayload(), 'paid' => $this->paid];
    }
}
