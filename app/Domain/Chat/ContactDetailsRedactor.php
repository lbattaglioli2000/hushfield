<?php

namespace App\Domain\Chat;

/**
 * Replaces links, email addresses, and phone numbers with the placeholder.
 */
final class ContactDetailsRedactor
{
    private const string URL_PATTERN = '~\b(?:https?://|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|gg|co|me|tv|ly|xyz|app|dev|info|biz|us|uk|ca|de|ru|link|site|online|club|live|chat)\b(?:/\S*)?~i';

    private const string EMAIL_PATTERN = '/[a-z0-9._%+-]+\s*(?:@|\(at\)|\[at\])\s*[a-z0-9.-]+\s*(?:\.|\(dot\)|\[dot\])\s*[a-z]{2,}/i';

    /** Seven or more digits with separators between them, starting and ending on a digit so surrounding spaces survive. */
    private const string PHONE_PATTERN = '/(?<!\d)\+?\(?\d(?:[\s().-]*\d){6,}(?!\d)/';

    /**
     * @return FilterResult Matches name the kinds found ("email", "link", "phone"), once per occurrence.
     */
    public function redact(string $body, string $placeholder): FilterResult
    {
        $matches = [];

        // Emails go first: the link pattern would otherwise take the domain and leave the address's name behind.
        foreach (['email' => self::EMAIL_PATTERN, 'link' => self::URL_PATTERN, 'phone' => self::PHONE_PATTERN] as $name => $pattern) {
            $body = (string) preg_replace_callback($pattern, function () use ($name, $placeholder, &$matches): string {
                $matches[] = $name;

                return $placeholder;
            }, $body);
        }

        return new FilterResult($body, $matches !== [], $matches);
    }
}
