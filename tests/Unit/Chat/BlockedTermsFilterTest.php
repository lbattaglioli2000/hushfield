<?php

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\FilterLevel;
use App\Models\BlockedTerm;

function blockTerm(string $term, BlockedTermMatchType $type = BlockedTermMatchType::Exact, BlockedTermSeverity $severity = BlockedTermSeverity::High): void
{
    BlockedTerm::factory()->create(['term' => $term, 'match_type' => $type, 'severity' => $severity]);
}

function hush(string $body, FilterLevel $level = FilterLevel::Strict): string
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

    expect(hush('grub'))->toBe(HUSHED)
        ->and(hush('gggrrrruuuubbbbbb'))->toBe(HUSHED);
});

it('sees through leetspeak, accents, and repeated letters', function (string $body) {
    blockTerm('grub');

    expect(hush("well {$body} then"))->toBe('well '.HUSHED.' then');
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

    expect(hush('hello class, the assistant passed the shell'))->toBe('hello class, the assistant passed the shell');
});

it('keeps a double letter in a term significant', function () {
    // Collapsing repeats must not turn "ass" into "as" and hush every "as" and "was".
    blockTerm('ass');
    blockTerm('loot', BlockedTermMatchType::Contains);

    expect(hush('as I was saying, a lot of us'))->toBe('as I was saying, a lot of us')
        ->and(hush('you aaasss'))->toBe('you '.HUSHED)
        ->and(hush('free l00000t here, or a lotto'))->toBe('free '.HUSHED.' here, or a lotto')
        ->and(hush('bigloot'))->toBe(HUSHED);
});

it('matches multi-word exact terms only as a run of whole words', function () {
    blockTerm('meet me');

    expect(hush('pls m33t   ME at the pier'))->toBe('pls '.HUSHED.' at the pier')
        ->and(hush('meet the mayor, me too'))->toBe('meet the mayor, me too')
        ->and(hush('meeting me'))->toBe('meeting me');
});

it('matches contains terms inside longer words', function () {
    blockTerm('grub', BlockedTermMatchType::Contains);

    expect(hush('such a grubby little gr8ubber'))->toBe('such a '.HUSHED.' little gr8ubber')
        ->and(hush('supergrubber'))->toBe(HUSHED);
});

it('matches regex terms against normalized words', function () {
    blockTerm('^sn[aiu]rk$', BlockedTermMatchType::Regex);

    expect(hush('what a snirk'))->toBe('what a '.HUSHED)
        ->and(hush('SNAAARK'))->toBe(HUSHED)
        ->and(hush('snorkel snark'))->toBe('snorkel '.HUSHED);
});

it('leaves clean messages untouched', function () {
    blockTerm('grub');

    $result = app(BlockedTermsFilter::class)->filter('Nice  hat, see you at the pier!', FilterLevel::Strict);

    expect($result->wasFiltered)->toBeFalse()
        ->and($result->body)->toBe('Nice  hat, see you at the pier!')
        ->and($result->matches)->toBe([]);
});

it('blocks links, email addresses, and phone numbers on strict', function (string $body, string $expected) {
    expect(hush($body, FilterLevel::Strict))->toBe($expected);
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
    expect(hush($body, FilterLevel::Standard))->toBe($body);
})->with([
    'go to https://example.com/x now',
    'mail kid@example.com ok',
    'call 555-123-4567 tonight',
]);

it('does not treat short numbers as phone numbers', function () {
    expect(hush('I have 25 coins and 300 shells', FilterLevel::Strict))->toBe('I have 25 coins and 300 shells');
});

it('blocks severities by filter level', function () {
    blockTerm('drat', severity: BlockedTermSeverity::Low);
    blockTerm('blast', severity: BlockedTermSeverity::Medium);
    blockTerm('grub', severity: BlockedTermSeverity::High);

    expect(hush('drat blast grub', FilterLevel::Strict))->toBe(HUSHED)
        ->and(hush('drat blast grub', FilterLevel::Standard))->toBe('drat '.HUSHED)
        ->and(hush('drat', FilterLevel::Standard))->toBe('drat');
});

it('sees new terms immediately after they are added', function () {
    expect(hush('grub'))->toBe('grub');

    blockTerm('grub');

    expect(hush('grub'))->toBe(HUSHED);
});

it('finds blocked terms anywhere in a username', function () {
    blockTerm('grub');
    blockTerm('^x+y+z+', BlockedTermMatchType::Regex);
    $filter = app(BlockedTermsFilter::class);

    expect($filter->containsBlockedTerm('Big_9rub_77'))->toBeTrue()
        ->and($filter->containsBlockedTerm('xxyz_fan'))->toBeTrue()
        ->and($filter->containsBlockedTerm('Penguin_99'))->toBeFalse();
});
