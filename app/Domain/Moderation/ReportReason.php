<?php

namespace App\Domain\Moderation;

enum ReportReason: string
{
    case Harassment = 'harassment';
    case Hate = 'hate';
    case Sexual = 'sexual';
    case PersonalInformation = 'personal_information';
    case Spam = 'spam';
    case Impersonation = 'impersonation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Harassment => 'Bullying or harassment',
            self::Hate => 'Hateful language',
            self::Sexual => 'Sexual content',
            self::PersonalInformation => 'Sharing or asking for personal information',
            self::Spam => 'Spam or scams',
            self::Impersonation => 'Pretending to be staff or someone else',
            self::Other => 'Something else',
        };
    }
}
