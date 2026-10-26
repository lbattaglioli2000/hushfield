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

    public static function insufficientCoins(): self
    {
        return new self('insufficient_coins', 422);
    }

    public static function itemOwned(): self
    {
        return new self('item_owned', 409);
    }

    public static function itemUnavailable(): self
    {
        return new self('item_unavailable', 422);
    }

    public static function itemMembersOnly(): self
    {
        return new self('item_members_only', 403);
    }

    public static function stipendClaimed(): self
    {
        return new self('stipend_claimed', 409);
    }

    /**
     * 409 rather than 503 like the other kill switches: the client shows a refusal's
     * own words only below 500, and players should read why the till is shut.
     */
    public static function shopClosed(): self
    {
        return new self('shop_closed', 409);
    }

    public static function shopElsewhere(): self
    {
        return new self('shop_elsewhere', 409);
    }

    public static function shopTooFast(): self
    {
        return new self('shop_too_fast', 429);
    }

    /**
     * 409 like the shop's switch, so players read why the Pier is shut.
     */
    public static function fishingClosed(): self
    {
        return new self('fishing_closed', 409);
    }

    public static function fishingElsewhere(): self
    {
        return new self('fishing_elsewhere', 409);
    }

    public static function fishingTooFar(): self
    {
        return new self('fishing_too_far', 422);
    }

    public static function fishingNoLine(): self
    {
        return new self('fishing_no_line', 409);
    }

    public static function fishingTooFast(): self
    {
        return new self('fishing_too_fast', 429);
    }

    public static function exitTooFar(): self
    {
        return new self('exit_too_far', 422);
    }

    public static function exitClosed(): self
    {
        return new self('exit_closed', 409);
    }

    public static function homeClosed(): self
    {
        return new self('home_closed', 403);
    }

    public static function homeFull(): self
    {
        return new self('home_full', 409);
    }

    public static function residencesClosed(): self
    {
        return new self('residences_closed', 409);
    }

    public static function notFurniture(): self
    {
        return new self('not_furniture', 422);
    }

    public static function furnitureBlocked(): self
    {
        return new self('furniture_blocked', 422);
    }

    public static function furnitureOffFloor(): self
    {
        return new self('furniture_off_floor', 422);
    }

    public static function furnitureInDoorway(): self
    {
        return new self('furniture_in_doorway', 422);
    }

    public static function furnitureCutsOff(): self
    {
        return new self('furniture_cuts_off', 422);
    }

    public static function furnitureTraps(): self
    {
        return new self('furniture_traps', 422);
    }

    public static function furnitureFull(): self
    {
        return new self('furniture_full', 409);
    }

    public static function homeTooFast(): self
    {
        return new self('home_too_fast', 429);
    }

    public static function friendUnknown(): self
    {
        return new self('friend_unknown', 404);
    }

    public static function friendSelf(): self
    {
        return new self('friend_self', 422);
    }

    public static function friendAlready(): self
    {
        return new self('friend_already', 409);
    }

    /**
     * The resident has requests turned off, or is in another age bracket; the two read the same.
     */
    public static function friendRequestsClosed(): self
    {
        return new self('friend_requests_closed', 403);
    }

    public static function friendRequestGone(): self
    {
        return new self('friend_request_gone', 404);
    }

    public static function friendTooManyRequests(): self
    {
        return new self('friend_too_many_requests', 409);
    }

    public static function friendListFull(): self
    {
        return new self('friend_list_full', 409);
    }

    public static function friendsTooFast(): self
    {
        return new self('friends_too_fast', 429);
    }
}
