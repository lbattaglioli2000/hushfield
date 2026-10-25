<?php

use App\Domain\Chat\ChatSanitizer;
use App\Domain\Chat\TextNormalizer;

it('strips invisible characters and flattens whitespace', function (string $raw, string $clean) {
    expect((new ChatSanitizer)->sanitize($raw))->toBe($clean);
})->with([
    'zero-width space inside a word' => ["g\u{200B}r\u{200C}u\u{200D}b", 'grub'],
    'bidi override' => ["\u{202E}olleh", 'olleh'],
    'byte order mark and soft hyphen' => ["\u{FEFF}hi\u{00AD}there", 'hithere'],
    'newlines and tabs' => ["hi\n\nthere\tfriend", 'hi there friend'],
    'surrounding and repeated spaces' => ['   hi     there  ', 'hi there'],
    'only invisible characters' => ["\u{200B}\u{2060}\u{FEFF}", ''],
    'ordinary text and emoji' => ['Nice hat! 🐧', 'Nice hat! 🐧'],
]);

it('normalizes text for matching', function (string $text, string $normalized) {
    expect((new TextNormalizer)->normalize($text))->toBe($normalized);
})->with([
    'case and leetspeak' => ['H3LL0 W0RLD', 'helo world'],
    'symbols' => ['$h@ll', 'shal'],
    'accents' => ['Crème Brûlée', 'creme brule'],
    'repeated letters' => ['soooo cooool', 'so col'],
    'digits that are not leetspeak stay' => ['area 26', 'area 26'],
]);

it('compacts text to letters and digits', function () {
    expect((new TextNormalizer)->compact('Big_Gr8b-99!'))->toBe('biggrbgi');
});
