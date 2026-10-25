<?php

namespace App\Http\Responses;

use App\Domain\World\Protocol;
use Illuminate\Http\JsonResponse;

/**
 * Every gameplay JSON response goes through here so it carries the protocol version.
 */
final class WorldResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['protocol' => Protocol::VERSION, ...$data], $status);
    }
}
