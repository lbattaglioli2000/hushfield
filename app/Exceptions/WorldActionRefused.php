<?php

namespace App\Exceptions;

use App\Http\Responses\WorldResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A gameplay request the server refuses. Rendered as JSON with a stable
 * machine-readable reason and a player-facing message from lang/en/hushfield.php.
 */
final class WorldActionRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
    ) {
        parent::__construct((string) __("hushfield.{$reason}"));
    }

    public static function unreachable(): self
    {
        return new self('unreachable', 422);
    }

    public static function notInRoom(): self
    {
        return new self('not_in_room', 409);
    }

    public static function sessionSuperseded(): self
    {
        return new self('session_moved', 409);
    }

    public static function sessionExpired(): self
    {
        return new self('session_expired', 409);
    }

    public static function banned(): self
    {
        return new self('banned', 403);
    }

    public static function joinsPaused(): self
    {
        return new self('joins_paused', 503);
    }

    public static function chatPaused(): self
    {
        return new self('chat_paused', 503);
    }

    public static function chatEmpty(): self
    {
        return new self('chat_empty', 422);
    }

    public static function chatTooLong(): self
    {
        return new self('chat_too_long', 422);
    }

    public static function chatTooFast(): self
    {
        return new self('chat_too_fast', 429);
    }

    public static function reportTooMany(): self
    {
        return new self('report_too_many', 429);
    }

    public static function reportSelf(): self
    {
        return new self('report_self', 422);
    }

    public function render(): JsonResponse
    {
        return WorldResponse::json([
            'reason' => $this->reason,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
