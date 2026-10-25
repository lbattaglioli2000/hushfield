<?php

namespace App\Domain\World;

/**
 * A door on a map: walking onto its tile takes the player to another room,
 * at the arrival point named by $arrive (or a random spawn).
 */
final readonly class MapExit
{
    public function __construct(
        public string $name,
        public int $x,
        public int $y,
        public string $to,
        public ?string $arrive,
    ) {}

    /**
     * @return array{name: string, x: int, y: int, to: string, arrive: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'x' => $this->x, 'y' => $this->y, 'to' => $this->to, 'arrive' => $this->arrive];
    }

    /**
     * @param  array{name: string, x: int, y: int, to: string, arrive: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['x'], $data['y'], $data['to'], $data['arrive']);
    }
}
