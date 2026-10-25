<?php

namespace App\Domain\Chat;

enum BlockedTermSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
