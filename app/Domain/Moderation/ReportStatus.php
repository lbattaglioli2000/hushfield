<?php

namespace App\Domain\Moderation;

enum ReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
