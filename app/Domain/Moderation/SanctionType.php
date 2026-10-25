<?php

namespace App\Domain\Moderation;

enum SanctionType: string
{
    /** Chat is blocked; the player still sees their own messages. */
    case Mute = 'mute';

    /** World access is blocked. */
    case Ban = 'ban';

    /** A recorded reminder with no restriction. */
    case Warning = 'warning';
}
