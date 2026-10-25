<?php

namespace App\Domain\Chat;

/**
 * Replaces blocked words with a fixed placeholder. Matching happens on the
 * normalized form of each word so "B4DD w0rd" matches "bad word"; replacement
 * happens on the original words so the rest of the message is untouched.
 */
final class BlockedTermsFilter implements ChatFilter
{
    private const string URL_PATTERN = '~\b(?:https?://|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|gg|co|me|tv|ly|xyz|app|dev|info|biz|us|uk|ca|de|ru|link|site|online|club|live|chat)\b(?:/\S*)?~i';

    private const string EMAIL_PATTERN = '/[a-z0-9._%+-]+\s*(?:@|\(at\)|\[at\])\s*[a-z0-9.-]+\s*(?:\.|\(dot\)|\[dot\])\s*[a-z]{2,}/i';

    /** Seven or more digits with separators between them, starting and ending on a digit so surrounding spaces survive. */
    private const string PHONE_PATTERN = '/(?<!\d)\+?\(?\d(?:[\s().-]*\d){6,}(?!\d)/';

    public function __construct(
        private readonly BlockedTermList $terms,
        private readonly TextNormalizer $normalizer,
    ) {}

    public function filter(string $body, FilterLevel $level): FilterResult
    {
        $placeholder = (string) __('hushfield.filtered_placeholder');
        $matches = [];

        if ($level->blocksContactDetails()) {
            // Emails go first: the link pattern would otherwise take the domain and leave the address's name behind.
            foreach (['email' => self::EMAIL_PATTERN, 'link' => self::URL_PATTERN, 'phone' => self::PHONE_PATTERN] as $name => $pattern) {
                $body = (string) preg_replace_callback($pattern, function () use ($name, $placeholder, &$matches): string {
                    $matches[] = $name;

                    return $placeholder;
                }, $body);
            }
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

        foreach ($this->terms->all() as $term) {
            if (! $level->blocks($term['severity'])) {
                continue;
            }

            foreach ($this->matchingIndexes($term, $wordIndexes, $normalized, $folded) as $indexes) {
                $matches[] = $term['term'];
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
     * @param  array{term: string, normalized: string, match_type: BlockedTermMatchType, severity: BlockedTermSeverity}  $term
     * @param  list<int>  $wordIndexes
     * @param  array<int, string>  $normalized
     * @param  array<int, string>  $folded
     * @return list<list<int>> Groups of token indexes that together form one match.
     */
    private function matchingIndexes(array $term, array $wordIndexes, array $normalized, array $folded): array
    {
        $groups = [];

        if ($term['match_type'] === BlockedTermMatchType::Exact) {
            $termPatterns = array_map(
                fn (string $word): string => '~^'.$this->normalizer->stretchPattern($word).'$~u',
                $this->termWords($term['term']),
            );
            $length = count($termPatterns);

            if ($length === 0) {
                return [];
            }

            for ($start = 0; $start + $length <= count($wordIndexes); $start++) {
                $window = array_slice($wordIndexes, $start, $length);

                foreach ($window as $offset => $index) {
                    if (preg_match($termPatterns[$offset], $folded[$index]) !== 1) {
                        continue 2;
                    }
                }

                $groups[] = $window;
            }

            return $groups;
        }

        $containsPattern = $term['match_type'] === BlockedTermMatchType::Contains
            ? '~'.$this->normalizer->stretchPattern(implode('', $this->termWords($term['term']))).'~u'
            : null;

        foreach ($wordIndexes as $index) {
            $isMatch = match ($term['match_type']) {
                BlockedTermMatchType::Contains => $containsPattern !== '~~u' && preg_match((string) $containsPattern, $folded[$index]) === 1,
                BlockedTermMatchType::Regex => @preg_match('~'.str_replace('~', '\~', $term['normalized']).'~iu', $normalized[$index]) === 1,
            };

            if ($isMatch) {
                $groups[] = [$index];
            }
        }

        return $groups;
    }

    /**
     * The term's words, folded, with everything but letters and digits removed.
     *
     * @return list<string>
     */
    private function termWords(string $term): array
    {
        return array_values(array_filter(array_map(
            fn (string $word): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', $word),
            preg_split('/\s+/u', $this->normalizer->fold($term)) ?: [],
        ), fn (string $word): bool => $word !== ''));
    }

    /**
     * True when any blocked term (of any severity) appears anywhere in the text.
     * Used for usernames, where there are no word boundaries to rely on.
     */
    public function containsBlockedTerm(string $text): bool
    {
        $compact = $this->normalizer->compact($text);
        $folded = (string) preg_replace('/[^a-z0-9]/', '', $this->normalizer->fold($text));

        foreach ($this->terms->all() as $term) {
            $hit = match ($term['match_type']) {
                BlockedTermMatchType::Regex => @preg_match('~'.str_replace('~', '\~', $term['term']).'~iu', $compact) === 1,
                default => ($needle = (string) preg_replace('/[^a-z0-9]/', '', $this->normalizer->fold($term['term']))) !== ''
                    && preg_match('~'.$this->normalizer->stretchPattern($needle).'~', $folded) === 1,
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
