<?php

namespace App\Domain\Books;

use App\Domain\Chat\ChatFilter;
use App\Domain\Chat\ContactDetailsRedactor;
use App\Domain\Chat\FilterLevel;

/**
 * Cleans a book's title and text, and checks them before they reach players
 * (some of them 13–17) with the chat's blocked terms list, at the strict
 * level. A blocked term stops a book being published. Links, email addresses
 * and phone numbers are only warnings: the phone pattern takes any run of
 * seven or more digits, so "1923–1931" counts as one.
 */
final readonly class BookText
{
    public function __construct(
        private ChatFilter $filter,
        private ContactDetailsRedactor $contactDetails,
    ) {}

    /**
     * Strips invisible characters (zero-width, bidi controls) and other control
     * characters, keeping line breaks, and at most one blank line in a row.
     */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = (string) preg_replace('/[^\P{Cc}\n]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array{blocked: list<string>, warnings: list<string>} The blocked terms found, and the kinds of contact details found ("email", "link", "phone").
     */
    public function check(string $title, string $body): array
    {
        $placeholder = (string) __('hushfield.filtered_placeholder');
        $contact = $this->contactDetails->redact("{$title}\n{$body}", $placeholder);
        // Contact details are already redacted, so what the filter finds now is blocked terms.
        $terms = $this->filter->filter($contact->body, FilterLevel::Strict);

        return [
            'blocked' => array_values(array_unique($terms->matches)),
            'warnings' => array_values(array_unique($contact->matches)),
        ];
    }
}
