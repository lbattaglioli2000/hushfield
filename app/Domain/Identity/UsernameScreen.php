<?php

namespace App\Domain\Identity;

/**
 * Decides whether a username contains language that is not allowed.
 */
interface UsernameScreen
{
    public function containsBlockedTerm(string $username): bool;
}
