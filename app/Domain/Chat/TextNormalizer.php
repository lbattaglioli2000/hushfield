<?php

namespace App\Domain\Chat;

/**
 * Folds text into a canonical form for matching: lowercase, common leetspeak
 * mapped to letters, accents removed, and runs of a repeated letter collapsed.
 */
final class TextNormalizer
{
    private const array LEET = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '9' => 'g',
        '@' => 'a', '$' => 's', '!' => 'i', '|' => 'l', '+' => 't', '€' => 'e', '£' => 'l',
    ];

    public function normalize(string $text): string
    {
        return (string) preg_replace('/(\p{L})\1+/u', '$1', $this->fold($text));
    }

    /**
     * Lowercase, leetspeak mapped, and accents removed, with repeated letters kept.
     */
    public function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, self::LEET);

        return transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?: $text;
    }

    /**
     * A regex fragment (for the ~ delimiter) matching the folded text with every
     * run of a letter stretched out, so "bad" matches "baaad" but "ass" never matches "as".
     */
    public function stretchPattern(string $folded): string
    {
        return (string) preg_replace_callback(
            '/(.)\1*/us',
            fn (array $run): string => preg_match('/\p{L}/u', $run[1]) ? preg_quote($run[1], '~').'{'.mb_strlen($run[0]).',}' : preg_quote($run[0], '~'),
            $folded,
        );
    }

    /**
     * Normalized form with everything except letters and digits removed.
     */
    public function compact(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', $this->normalize($text));
    }
}
