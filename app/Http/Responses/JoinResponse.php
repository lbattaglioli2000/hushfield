<?php

namespace App\Http\Responses;

use App\Actions\World\BuildSnapshot;
use App\Domain\World\JoinResult;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The answer to anything that puts a player in a room: a notice when they
 * overflowed, and the snapshot to draw.
 */
final readonly class JoinResponse
{
    public function __construct(private BuildSnapshot $buildSnapshot) {}

    public function for(User $user, JoinResult $result): JsonResponse
    {
        return WorldResponse::json([
            'notice' => $result->overflowed ? __('hushfield.instance_full') : null,
            'snapshot' => ($this->buildSnapshot)($user, $result->instance)->toArray(),
        ]);
    }
}
