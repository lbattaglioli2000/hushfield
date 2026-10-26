<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A staff action (moderation, roles, coins, items) the server refuses. The message is for staff, not players.
 */
final class ModerationActionRefused extends RuntimeException
{
    public static function staffTarget(): self
    {
        return new self('Staff accounts cannot be sanctioned.');
    }

    public static function reportNotOpen(): self
    {
        return new self('This report has already been resolved.');
    }

    public static function zeroAdjustment(): self
    {
        return new self('Enter an amount other than zero.');
    }

    public static function insufficientCoins(int $balance): self
    {
        return new self("That would take the balance below zero. It is {$balance}.");
    }

    public static function alreadyOwned(): self
    {
        return new self('This resident already owns that item.');
    }

    public static function notFurniture(): self
    {
        return new self('Only furniture has facings to upload art for.');
    }

    public static function notArtPublisher(): self
    {
        return new self('Only the art publisher can publish or unpublish item art.');
    }

    public static function staleArt(): self
    {
        return new self('This art was drawn for an older size or shape of the item. Upload new art instead.');
    }
}
