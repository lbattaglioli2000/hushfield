<?php

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Domain\Identity\UsernameScreen;

it('finds blocked terms of any severity anywhere in a username', function () {
    blockTerm('grub', severity: BlockedTermSeverity::Low);
    blockTerm('^x+y+z+', BlockedTermMatchType::Regex);
    $screen = app(UsernameScreen::class);

    expect($screen->containsBlockedTerm('Big_9rub_77'))->toBeTrue()
        ->and($screen->containsBlockedTerm('xxyz_fan'))->toBeTrue()
        ->and($screen->containsBlockedTerm('Penguin_99'))->toBeFalse();
});

it('finds multi-word and contains terms run together', function () {
    blockTerm('meet me');
    blockTerm('loot', BlockedTermMatchType::Contains);
    $screen = app(UsernameScreen::class);

    expect($screen->containsBlockedTerm('MeetMe_Now'))->toBeTrue()
        ->and($screen->containsBlockedTerm('l00t_hunter'))->toBeTrue()
        ->and($screen->containsBlockedTerm('lot_hunter'))->toBeFalse();
});
