<?php

namespace App\Domain\Economy;

use RuntimeException;

/**
 * A debit that would take a balance below zero. Callers translate it for their audience.
 */
final class InsufficientCoins extends RuntimeException
{
    public function __construct(public readonly int $balance, public readonly int $amount)
    {
        parent::__construct("A debit of {$amount} exceeds the balance of {$balance}.");
    }
}
