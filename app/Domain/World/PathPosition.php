<?php

namespace App\Domain\World;

/**
 * Where a walker is along a path at one instant.
 */
final readonly class PathPosition
{
    /**
     * @param  array{int, int}  $tile  Nearest tile (the segment start before the halfway point, the segment end after).
     * @param  array{float, float}  $offset  Position minus tile, each component within [-0.5, 0.5].
     * @param  array{int, int}  $from  Start tile of the segment being walked (the last tile once finished).
     * @param  array{int, int}  $to  End tile of the segment being walked (the last tile once finished).
     */
    public function __construct(
        public float $x,
        public float $y,
        public array $tile,
        public array $offset,
        public int $segment,
        public array $from,
        public array $to,
        public float $progress,
        public float $segmentElapsedMs,
        public bool $finished,
    ) {}

    /**
     * @return array{tile: array{int, int}, offset: array{float, float}, segment: int, progress: float, finished: bool}
     */
    public function toArray(): array
    {
        return [
            'tile' => $this->tile,
            'offset' => $this->offset,
            'segment' => $this->segment,
            'progress' => $this->progress,
            'finished' => $this->finished,
        ];
    }
}
