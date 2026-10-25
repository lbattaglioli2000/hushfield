<?php

namespace App\Domain\Chat;

/**
 * Cleans raw chat input before validation and filtering.
 */
final class ChatSanitizer
{
    public function sanitize(string $body): string
    {
        // Zero-width and invisible formatting characters (ZWSP, ZWNJ, ZWJ, word joiner, BOM, bidi controls).
        $body = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $body);
        // Control characters, including newlines and tabs, become spaces.
        $body = (string) preg_replace('/[\p{Cc}]/u', ' ', $body);
        $body = (string) preg_replace('/\s{2,}/u', ' ', $body);

        return trim($body);
    }
}
