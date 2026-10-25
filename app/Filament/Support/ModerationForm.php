<?php

namespace App\Filament\Support;

use App\Domain\Moderation\SanctionType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;

/**
 * The reason and end date fields shared by every sanction and report action.
 */
final class ModerationForm
{
    /**
     * @return list<Component>
     */
    public static function fields(?SanctionType $type): array
    {
        $fields = [
            Textarea::make('reason')
                ->label('Reason')
                ->helperText('Recorded in the audit log.')
                ->required()
                ->maxLength(255)
                ->rows(3),
        ];

        if ($type === SanctionType::Mute || $type === SanctionType::Ban) {
            $fields[] = DateTimePicker::make('ends_at')
                ->label('Ends at')
                ->seconds(false)
                ->after('now')
                ->helperText($type === SanctionType::Ban
                    ? 'Leave empty for a permanent ban.'
                    : 'Leave empty for a mute with no end date.');
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function reason(array $data): string
    {
        return (string) $data['reason'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function endsAt(array $data): ?CarbonInterface
    {
        $endsAt = $data['ends_at'] ?? null;

        return is_string($endsAt) && $endsAt !== '' ? CarbonImmutable::parse($endsAt) : null;
    }
}
