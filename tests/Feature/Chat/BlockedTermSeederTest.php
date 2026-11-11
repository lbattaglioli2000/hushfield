<?php

use App\Domain\Chat\BlockedTermList;
use App\Domain\Chat\BlockedTermSeverity;
use App\Domain\Chat\BlockedTermsFilter;
use App\Domain\Chat\FilterLevel;
use App\Domain\Identity\UsernameScreen;
use App\Models\BlockedTerm;
use Database\Seeders\BlockedTermSeeder;

beforeEach(function () {
    $this->seed(BlockedTermSeeder::class);
});

it('seeds terms that all compile, and seeding again keeps staff edits', function () {
    $count = BlockedTerm::query()->count();
    BlockedTerm::query()->where('term', 'idiot')->sole()->update(['severity' => BlockedTermSeverity::High]);

    $this->seed(BlockedTermSeeder::class);

    expect(BlockedTerm::query()->count())->toBe($count)
        ->and(app(BlockedTermList::class)->all())->toHaveCount($count)
        ->and(BlockedTerm::query()->where('term', 'idiot')->sole()->severity)->toBe(BlockedTermSeverity::High);
});

it('hushes abuse on every filter, through leetspeak and stretching', function (string $body, string $term) {
    $result = app(BlockedTermsFilter::class)->filter($body, FilterLevel::Standard);

    expect($result->wasFiltered)->toBeTrue()
        ->and($result->matches)->toContain($term);
})->with([
    ['shut up you f4gg0t', 'fagot'],
    ['ok n1gg3r', 'nigger'],
    ['look at that spic', '^spics?$'],
    ['what a N4Z1', '^(neo)?nazis?$'],
    ['the KKK', 'kkk'],
    ['just kys', '^kys$'],
    ['go k1ll urself', 'kill urself'],
    ['KILL   YOURSELF', 'kill yourself'],
    ['send me a pic pls', 'send me a pic'],
    ["don't tell your mom", 'dont tell your mom'],
    ['what the fuuuuck', 'fuck'],
    ['fk off', '^(fk|fck|fuk|fuq|phuk|phuck)(s|er|ers|ed|ing|in)?$'],
    ['this is bullsh1t', 'shit'],
    ['what a d1ck', '^dicks?$'],
    ['you retarded or what', 'retard'],
]);

it('hushes milder words and other apps only on the strict filter', function (string $body, string $term) {
    $filter = app(BlockedTermsFilter::class);

    expect($filter->filter($body, FilterLevel::Strict)->matches)->toContain($term)
        ->and($filter->filter($body, FilterLevel::Standard)->wasFiltered)->toBeFalse();
})->with([
    ['what a m0r0n', 'moron'],
    ['this is crappy', '^crap(s|y)?$'],
    ['stfu', '^stfu$'],
    ['add me on snapchat', 'snapchat'],
    ['add me on it', 'add me on'],
    ['my discord is grub', 'discord'],
]);

it('leaves innocent words that contain a blocked term alone', function (string $body) {
    expect(app(BlockedTermsFilter::class)->filter($body, FilterLevel::Strict)->wasFiltered)->toBeFalse();
})->with([
    'a raccoon and a tycoon by the cocoon',
    'the peacock and the cockatoo met Hancock',
    'from Japan to Pakistan',
    'spicy spices, said the therapist',
    'as I was saying, the class assistant passed the glass',
    'grapes and drapes for the analyst',
    'Dickens wrote a classic',
    'Essex has a sextant and a sexton',
    'the cucumber document is cumulative',
    'the heroine has a method',
    'hello Michelle, the shell is restful',
    'goodies for the skyscraper',
    'a titanic titan with a title',
    'the thorny rose',
    'the arsenal parsed it',
    'the scrap heap is snappy',
    'a swanky debugger',
    'woops, Nazir is Ashkenazi',
]);

it('accepts usernames made of innocent words, and refuses ones with a blocked term', function (string $username, bool $blocked) {
    expect(app(UsernameScreen::class)->containsBlockedTerm($username))->toBe($blocked);
})->with([
    ['Skystar', false],
    ['Restful_Fox', false],
    ['Goodie_Bag', false],
    ['Cassie', false],
    ['Raccoon_Pal', false],
    ['Japan_Fan', false],
    ['Spicy_Mango', false],
    ['Peacock', false],
    ['Sextant', false],
    ['Thorny_Rose', false],
    ['Booboo_Bear', false],
    ['Method_Man', false],
    ['Scrappy', false],
    ['Nazim_Ahmed', false],
    ['Kys', true],
    ['xX_f4ggot_Xx', true],
    ['BigSh1t99', true],
    ['SnapchatMe', true],
    ['Kill_Urself', true],
]);
