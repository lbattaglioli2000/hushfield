<?php

namespace App\Domain\Chat;

/**
 * One blocked term with its match patterns compiled once, when the list is cached.
 */
final readonly class BlockedTermRule
{
    /**
     * @param  list<string>  $chatPatterns  Exact: one anchored pattern per term word, matched against consecutive folded words.
     *                                      Contains: one pattern matched inside each folded word.
     *                                      Regex: the moderator's pattern, matched against each normalized word.
     * @param  string|null  $usernamePattern  Matched anywhere in a username; null when the term can never match one.
     */
    public function __construct(
        public string $term,
        public BlockedTermMatchType $matchType,
        public BlockedTermSeverity $severity,
        public array $chatPatterns,
        public ?string $usernamePattern,
    ) {}

    /**
     * @return array{term: string, match_type: string, severity: string, chat_patterns: list<string>, username_pattern: string|null}
     */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'match_type' => $this->matchType->value,
            'severity' => $this->severity->value,
            'chat_patterns' => $this->chatPatterns,
            'username_pattern' => $this->usernamePattern,
        ];
    }

    /**
     * @param  array{term: string, match_type: string, severity: string, chat_patterns: list<string>, username_pattern: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['term'],
            BlockedTermMatchType::from($data['match_type']),
            BlockedTermSeverity::from($data['severity']),
            $data['chat_patterns'],
            $data['username_pattern'],
        );
    }
}
