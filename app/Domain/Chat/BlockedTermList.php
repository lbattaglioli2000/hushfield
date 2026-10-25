<?php

namespace App\Domain\Chat;

use App\Models\BlockedTerm;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The blocked_terms table compiled into match rules, cached in the shared cache
 * store and cleared whenever a term changes.
 *
 * A regex term that does not compile is left out: it could never match anything.
 */
final class BlockedTermList
{
    /** Versioned: bump when the cached shape of a rule changes. */
    private const string CACHE_KEY = 'chat:blocked-terms:v2';

    public function __construct(
        private readonly Cache $cache,
        private readonly TextNormalizer $normalizer,
    ) {}

    /**
     * @return list<BlockedTermRule>
     */
    public function all(): array
    {
        /** @var list<array{term: string, match_type: string, severity: string, chat_patterns: list<string>, username_pattern: string|null}> $rules */
        $rules = $this->cache->rememberForever(self::CACHE_KEY, fn (): array => BlockedTerm::query()
            ->orderBy('id')
            ->get()
            ->map($this->compile(...))
            ->filter()
            ->map(fn (BlockedTermRule $rule): array => $rule->toArray())
            ->values()
            ->all());

        return array_map(BlockedTermRule::fromArray(...), $rules);
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private function compile(BlockedTerm $term): ?BlockedTermRule
    {
        if ($term->match_type === BlockedTermMatchType::Regex) {
            $pattern = '~'.str_replace('~', '\~', $term->term).'~iu';

            return @preg_match($pattern, '') === false
                ? null
                : new BlockedTermRule($term->term, $term->match_type, $term->severity, [$pattern], $pattern);
        }

        $words = $this->termWords($term->term);

        $chatPatterns = match ($term->match_type) {
            BlockedTermMatchType::Exact => array_map(fn (string $word): string => '~^'.$this->normalizer->stretchPattern($word).'$~u', $words),
            BlockedTermMatchType::Contains => $words === [] ? [] : ['~'.$this->normalizer->stretchPattern(implode('', $words)).'~u'],
        };

        $needle = (string) preg_replace('/[^a-z0-9]/', '', $this->normalizer->fold($term->term));

        return new BlockedTermRule(
            $term->term,
            $term->match_type,
            $term->severity,
            $chatPatterns,
            $needle === '' ? null : '~'.$this->normalizer->stretchPattern($needle).'~',
        );
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
}
