<?php

namespace App\Domain\Chat;

use App\Domain\Identity\UsernameScreen;

/**
 * Screens usernames against every blocked term, of any severity, anywhere in
 * the name: usernames have no word boundaries to rely on.
 */
final readonly class BlockedTermsUsernameScreen implements UsernameScreen
{
    public function __construct(
        private BlockedTermList $terms,
        private TextNormalizer $normalizer,
    ) {}

    public function containsBlockedTerm(string $username): bool
    {
        $compact = $this->normalizer->compact($username);
        $folded = (string) preg_replace('/[^a-z0-9]/', '', $this->normalizer->fold($username));

        foreach ($this->terms->all() as $rule) {
            if ($rule->usernamePattern === null) {
                continue;
            }

            $subject = $rule->matchType === BlockedTermMatchType::Regex ? $compact : $folded;

            if (preg_match($rule->usernamePattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }
}
