<?php

namespace App\Exceptions;

use App\Models\Book;
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

    public static function notVariantParent(): self
    {
        return new self('Only a furniture item that is not itself a colour of another can have colours.');
    }

    public static function variantExists(string $slug): self
    {
        return new self("An item with the slug {$slug} already exists. Name the colour differently.");
    }

    /**
     * @param  list<string>  $terms
     */
    public static function bookBlocked(array $terms): self
    {
        return new self('This book has words on the blocked terms list: '.implode(', ', $terms).'. Reword them, then try again.');
    }

    /**
     * @param  list<string>  $kinds  "email", "link" or "phone".
     */
    public static function bookNeedsConfirming(array $kinds): self
    {
        return new self('This book looks like it has '.self::contactKinds($kinds).'. Check it, then confirm you want to publish it anyway.');
    }

    public static function bookNotDraft(): self
    {
        return new self('This book has already been published.');
    }

    public static function bookNotPublished(): self
    {
        return new self('Only a published book can be withdrawn, or given out.');
    }

    public static function bookNotWithdrawn(): self
    {
        return new self('This book has not been withdrawn.');
    }

    public static function bookTooLong(): self
    {
        return new self('A book needs a title of up to '.Book::MAX_TITLE.' characters, and text of up to '.number_format(Book::MAX_BODY).'.');
    }

    public static function bookAlreadyHeld(string $username): self
    {
        return new self("{$username} already has a copy of this book. Each resident holds one copy at most.");
    }

    public static function shelfInUse(int $books): self
    {
        return new self("A bookshelf of this item already holds {$books} books, so it can't hold fewer.");
    }

    /**
     * "a link and a phone number", for staff.
     *
     * @param  list<string>  $kinds
     */
    public static function contactKinds(array $kinds): string
    {
        $names = array_map(fn (string $kind): string => match ($kind) {
            'email' => 'an email address',
            'phone' => 'a phone number (any run of seven or more digits, such as a span of years, looks like one)',
            default => 'a link',
        }, $kinds);

        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : implode('', $names);
    }
}
