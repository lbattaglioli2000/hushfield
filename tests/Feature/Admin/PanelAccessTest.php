<?php

use App\Domain\Identity\StaffRole;
use App\Filament\Pages\FeatureFlags;
use App\Filament\Pages\LiveRooms;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\BlockedTerms\BlockedTermResource;
use App\Filament\Resources\ChatReports\ChatReportResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;

test('moderators cannot reach admin-only pages', function (Closure $url) {
    $this->actingAs(User::factory()->staff()->create())
        ->get($url())
        ->assertForbidden();
})->with([
    'blocked terms' => fn (): string => BlockedTermResource::getUrl('index'),
    'feature flags' => fn (): string => FeatureFlags::getUrl(),
]);

test('every page renders for an admin', function (Closure $url) {
    importRoom();

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create())
        ->get($url())
        ->assertOk();
})->with([
    'dashboard' => fn (): string => route('filament.admin.pages.dashboard'),
    'users' => fn (): string => UserResource::getUrl('index'),
    'a user' => fn (): string => UserResource::getUrl('view', ['record' => resident()]),
    'reports queue' => fn (): string => ChatReportResource::getUrl('index'),
    'live rooms' => fn (): string => LiveRooms::getUrl(),
    'blocked terms' => fn (): string => BlockedTermResource::getUrl('index'),
    'feature flags' => fn (): string => FeatureFlags::getUrl(),
    'audit log' => fn (): string => ActivityResource::getUrl('index'),
]);

test('players cannot reach the panel', function () {
    $this->actingAs(resident())
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();
});
