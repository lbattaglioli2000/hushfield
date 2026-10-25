<?php

namespace App\Domain\Chat;

use App\Models\BlockedTerm;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The blocked_terms table, cached in the shared cache store and cleared whenever a term changes.
 */
final class BlockedTermList
{
    private const string CACHE_KEY = 'chat:blocked-terms';

    public function __construct(
        private readonly Cache $cache,
        private readonly TextNormalizer $normalizer,
    ) {}

    /**
     * @return list<array{term: string, normalized: string, match_type: BlockedTermMatchType, severity: BlockedTermSeverity}>
     */
    public function all(): array
    {
        /** @var list<array{term: string, normalized: string, match_type: string, severity: string}> $terms */
        $terms = $this->cache->rememberForever(self::CACHE_KEY, fn (): array => BlockedTerm::query()
            ->orderBy('id')
            ->get()
            ->map(fn (BlockedTerm $term): array => [
                'term' => $term->term,
                'normalized' => $term->match_type === BlockedTermMatchType::Regex ? $term->term : $this->normalizer->normalize($term->term),
                'match_type' => $term->match_type->value,
                'severity' => $term->severity->value,
            ])
            ->all());

        return array_map(fn (array $term): array => [
            ...$term,
            'match_type' => BlockedTermMatchType::from($term['match_type']),
            'severity' => BlockedTermSeverity::from($term['severity']),
        ], $terms);
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
