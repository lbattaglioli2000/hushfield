<?php

namespace App\Actions\Identity;

use App\Domain\Chat\FilterLevel;
use App\Domain\Identity\AgePolicy;
use App\Models\User;

/**
 * Recomputes the age bracket and enforces the defaults that follow from it.
 * Runs on registration and on every login.
 */
final readonly class ApplyAccountPolicy
{
    public function __construct(private AgePolicy $ages) {}

    public function __invoke(User $user): User
    {
        $wasMinor = $user->exists ? $user->is_minor : null;
        $user->is_minor = $this->ages->isMinor($user->birth_year);

        if ($user->is_minor) {
            $user->chat_filter_level = FilterLevel::Strict;
        }

        if ($wasMinor === null) {
            // Strict filtering is the default for everyone. Social requests start off for
            // minors; adults start with them on. Future DM and friend features read these columns.
            $user->chat_filter_level = FilterLevel::Strict;
            $user->direct_messages_allowed = ! $user->is_minor;
            $user->friend_requests_allowed = ! $user->is_minor;
        }

        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
