<?php

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\FilterLevel;
use App\Models\BlockedTerm;

function filteredBody(string $body, FilterLevel $level = FilterLevel::Strict): string
{
    return app(BlockedTermsFilter::class)->filter($body, $level)->body;
}

const HUSHED = '(hushed)';

it('uses the fixed placeholder from the copy file', function () {
    expect(__('hushfield.filtered_placeholder'))->toBe(HUSHED);
});

it('replaces an exact term with the placeholder, not asterisks', function () {
    blockTerm('grub');

    $result = app(BlockedTermsFilter::class)->filter('you are grub!', FilterLevel::Strict);

    expect($result->body)->toBe('you are '.HUSHED)
        ->and($result->body)->not->toContain('*')
        ->and($result->wasFiltered)->toBeTrue()
        ->and($result->matches)->toBe(['grub']);
});

it('uses the same placeholder whatever the length of the hidden word', function () {
    blockTerm('grub');

    expect(filteredBody('grub'))->toBe(HUSHED)
        ->and(filteredBody('gggrrrruuuubbbbbb'))->toBe(HUSHED);
});

it('sees through leetspeak, accents, and repeated letters', function (string $body) {
    blockTerm('grub');

    expect(filteredBody("well {$body} then"))->toBe('well '.HUSHED.' then');
})->with([
    'digits' => '9rub',
    'symbols' => 'gru8',
    'mixed case' => 'GrUb',
    'accents' => 'grüb',
    'repeated letters' => 'grrruuuub',
    'everything at once' => 'GGRRÜÜ88',
]);

it('does not flag innocent words that contain an exact term', function () {
    blockTerm('ass');
    blockTerm('hell');

    expect(filteredBody('hello class, the assistant passed the shell'))->toBe('hello class, the assistant passed the shell');
});

it('keeps a double letter in a term significant', function () {
    // Collapsing repeats must not turn "ass" into "as" and hush every "as" and "was".
    blockTerm('ass');
    blockTerm('loot', BlockedTermMatchType::Contains);

    expect(filteredBody('as I was saying, a lot of us'))->toBe('as I was saying, a lot of us')
        ->and(filteredBody('you aaasss'))->toBe('you '.HUSHED)
        ->and(filteredBody('free l00000t here, or a lotto'))->toBe('free '.HUSHED.' here, or a lotto')
        ->and(filteredBody('bigloot'))->toBe(HUSHED);
});

it('matches multi-word exact terms only as a run of whole words', function () {
    blockTerm('meet me');

    expect(filteredBody('pls m33t   ME at the pier'))->toBe('pls '.HUSHED.' at the pier')
        ->and(filteredBody('meet the mayor, me too'))->toBe('meet the mayor, me too')
        ->and(filteredBody('meeting me'))->toBe('meeting me');
});

it('matches contains terms inside longer words', function () {
    blockTerm('grub', BlockedTermMatchType::Contains);

    expect(filteredBody('such a grubby little gr8ubber'))->toBe('such a '.HUSHED.' little gr8ubber')
        ->and(filteredBody('supergrubber'))->toBe(HUSHED);
});

it('matches regex terms against normalized words', function () {
    blockTerm('^sn[aiu]rk$', BlockedTermMatchType::Regex);

    expect(filteredBody('what a snirk'))->toBe('what a '.HUSHED)
        ->and(filteredBody('SNAAARK'))->toBe(HUSHED)
        ->and(filteredBody('snorkel snark'))->toBe('snorkel '.HUSHED);
});

it('leaves clean messages untouched', function () {
    blockTerm('grub');

    $result = app(BlockedTermsFilter::class)->filter('Nice  hat, see you at the pier!', FilterLevel::Strict);

    expect($result->wasFiltered)->toBeFalse()
        ->and($result->body)->toBe('Nice  hat, see you at the pier!')
        ->and($result->matches)->toBe([]);
});

it('blocks links, email addresses, and phone numbers on strict', function (string $body, string $expected) {
    expect(filteredBody($body, FilterLevel::Strict))->toBe($expected);
})->with([
    'url' => ['go to https://example.com/x now', 'go to '.HUSHED.' now'],
    'bare domain' => ['visit coolsite.gg', 'visit '.HUSHED],
    'www' => ['www.example.org is fun', HUSHED.' is fun'],
    'email' => ['mail kid@example.com ok', 'mail '.HUSHED.' ok'],
    'obfuscated email' => ['kid (at) example (dot) com', HUSHED],
    'phone' => ['call 555-123-4567 tonight', 'call '.HUSHED.' tonight'],
    'spaced phone' => ['my number is +1 (555) 123 4567', 'my number is '.HUSHED],
]);

it('allows links, email addresses, and phone numbers on standard', function (string $body) {
    expect(filteredBody($body, FilterLevel::Standard))->toBe($body);
})->with([
    'go to https://example.com/x now',
    'mail kid@example.com ok',
    'call 555-123-4567 tonight',
]);

it('does not treat short numbers as phone numbers', function () {
    expect(filteredBody('I have 25 coins and 300 shells', FilterLevel::Strict))->toBe('I have 25 coins and 300 shells');
});

it('blocks severities by filter level', function () {
    blockTerm('drat', severity: BlockedTermSeverity::Low);
    blockTerm('blast', severity: BlockedTermSeverity::Medium);
    blockTerm('grub', severity: BlockedTermSeverity::High);

    expect(filteredBody('drat blast grub', FilterLevel::Strict))->toBe(HUSHED)
        ->and(filteredBody('drat blast grub', FilterLevel::Standard))->toBe('drat '.HUSHED)
        ->and(filteredBody('drat', FilterLevel::Standard))->toBe('drat');
});

it('sees new terms immediately after they are added', function () {
    expect(filteredBody('grub'))->toBe('grub');

    blockTerm('grub');

    expect(filteredBody('grub'))->toBe(HUSHED);
});

it('ignores a regex term that does not compile', function () {
    BlockedTerm::factory()->create(['term' => '(unclosed', 'match_type' => BlockedTermMatchType::Regex]);
    blockTerm('grub');

    expect(filteredBody('(unclosed grub'))->toBe('(unclosed '.HUSHED);
});

it('reports contact details by kind', function () {
    $result = app(BlockedTermsFilter::class)->filter('mail kid@example.com or call 555-123-4567', FilterLevel::Strict);

    expect($result->matches)->toBe(['email', 'phone']);
});
