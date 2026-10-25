<?php

namespace App\Domain\Identity;

enum StaffRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
}
