<?php

namespace App\Domain\Chat;

/**
 * Replaces blocked words with a fixed placeholder. Matching happens on the
 * normalized form of each word so "B4DD w0rd" matches "bad word"; replacement
 * happens on the original words so the rest of the message is untouched.
 * On strict filtering, contact details are redacted first.
 */
final readonly class BlockedTermsFilter implements ChatFilter
{
    public function __construct(
        private BlockedTermList $terms,
        private TextNormalizer $normalizer,
        private ContactDetailsRedactor $contactDetails,
    ) {}

    public function filter(string $body, FilterLevel $level): FilterResult
    {
        $placeholder = (string) __('hushfield.filtered_placeholder');
        $matches = [];

        if ($level->blocksContactDetails()) {
            $redacted = $this->contactDetails->redact($body, $placeholder);
            $body = $redacted->body;
            $matches = $redacted->matches;
        }

        // Split into words and the separators between them, keeping both.
        $tokens = preg_split('/(\s+)/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $wordIndexes = array_keys(array_filter($tokens, fn (string $token): bool => trim($token) !== '' && $token !== $placeholder));
        $normalized = [];
        $folded = [];

        foreach ($wordIndexes as $index) {
            // Trim ordinary punctuation from the edges first, so "bad!" is "bad" while "b!d" still reads as leetspeak for "bid".
            $word = (string) preg_replace('/^[.,!?;:\'"()\[\]{}<>*~-]+|[.,!?;:\'"()\[\]{}<>*~-]+$/u', '', $tokens[$index]);
            $normalized[$index] = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $this->normalizer->normalize($word));
            $folded[$index] = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $this->normalizer->fold($word));
        }

        foreach ($this->terms->all() as $rule) {
            if (! $level->blocks($rule->severity)) {
                continue;
            }

            foreach ($this->matchingIndexes($rule, $wordIndexes, $normalized, $folded) as $indexes) {
                $matches[] = $rule->term;
                $first = array_shift($indexes);
                $tokens[$first] = $placeholder;

                foreach ($indexes as $index) {
                    $tokens[$index] = '';
                }
            }
        }

        if ($matches === []) {
            return new FilterResult($body, false);
        }

        $filtered = trim((string) preg_replace('/\s{2,}/u', ' ', implode('', $tokens)));
        $filtered = (string) preg_replace('/(?:'.preg_quote($placeholder, '/').'\s*){2,}/u', $placeholder.' ', $filtered);

        return new FilterResult(trim($filtered), true, array_values(array_unique($matches)));
    }

    /**
     * Exact and contains terms match on the folded words with letter runs stretched, so
     * "baaad" matches "bad" while a double letter in the term ("ass") still has to be there.
     *
     * @param  list<int>  $wordIndexes
     * @param  array<int, string>  $normalized
     * @param  array<int, string>  $folded
     * @return list<list<int>> Groups of token indexes that together form one match.
     */
    private function matchingIndexes(BlockedTermRule $rule, array $wordIndexes, array $normalized, array $folded): array
    {
        $patterns = $rule->chatPatterns;
        $length = count($patterns);
        $groups = [];

        if ($length === 0) {
            return [];
        }

        if ($rule->matchType === BlockedTermMatchType::Exact) {
            for ($start = 0; $start + $length <= count($wordIndexes); $start++) {
                $window = array_slice($wordIndexes, $start, $length);

                foreach ($window as $offset => $index) {
                    if (preg_match($patterns[$offset], $folded[$index]) !== 1) {
                        continue 2;
                    }
                }

                $groups[] = $window;
            }

            return $groups;
        }

        $words = $rule->matchType === BlockedTermMatchType::Regex ? $normalized : $folded;

        foreach ($wordIndexes as $index) {
            if (preg_match($patterns[0], $words[$index]) === 1) {
                $groups[] = [$index];
            }
        }

        return $groups;
    }
}
