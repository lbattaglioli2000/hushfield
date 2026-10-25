<?php

namespace App\Rules;

use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\TextNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 3–16 letters and numbers, with single underscores between them, that are
 * neither reserved nor contain a blocked term (leetspeak included).
 * Uniqueness is checked separately against the case-insensitive column.
 */
final class ValidUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $min = (int) config('hushfield.accounts.username.min');
        $max = (int) config('hushfield.accounts.username.max');

        if (! is_string($value) || mb_strlen($value) < $min || mb_strlen($value) > $max || ! preg_match('/^[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*$/', $value)) {
            $fail(__('hushfield.username_format'));

            return;
        }

        if ($this->isReserved($value)) {
            $fail(__('hushfield.username_reserved'));

            return;
        }

        if (app(BlockedTermsFilter::class)->containsBlockedTerm($value)) {
            $fail(__('hushfield.username_blocked'));
        }
    }

    private function isReserved(string $username): bool
    {
        $normalizer = app(TextNormalizer::class);
        /** @var list<string> $reserved */
        $reserved = array_map($normalizer->compact(...), (array) config('hushfield.accounts.username.reserved'));
        /** @var list<string> $fragments */
        $fragments = array_map($normalizer->compact(...), (array) config('hushfield.accounts.username.reserved_fragments'));

        $candidates = [$normalizer->compact($username), ...array_map($normalizer->compact(...), explode('_', $username))];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $reserved, true)) {
                return true;
            }
        }

        $whole = $normalizer->compact($username);

        foreach ($fragments as $fragment) {
            if (str_contains($whole, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
