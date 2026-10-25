<?php

namespace App\Domain\World;

use App\Models\User;

/**
 * One player as clients draw them: who they are and the path they are walking.
 */
final readonly class PlayerState
{
    public function __construct(
        public User $user,
        public StoredPath $path,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->user->toPlayerPayload(),
            ...$this->path->toArray(),
        ];
    }
}
