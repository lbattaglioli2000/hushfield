<?php

namespace App\Filament\Support;

use App\Domain\Moderation\SanctionType;
use App\Exceptions\ModerationActionRefused;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;

/**
 * The reason and end date fields shared by every audited staff action, and the
 * handling of a moderation action the server refuses.
 */
final class ModerationForm
{
    /**
     * @return list<Component>
     */
    public static function fields(?SanctionType $type): array
    {
        $fields = [self::reasonField()];

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

    public static function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label('Reason')
            ->helperText('Recorded in the audit log.')
            ->required()
            ->maxLength(255)
            ->rows(3);
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

    /**
     * Runs the moderation action, showing a refusal to staff as a danger
     * notification instead of an error page.
     *
     * @param  Closure(): mixed  $run
     */
    public static function runOrNotify(Action $action, Closure $run): void
    {
        try {
            $run();
        } catch (ModerationActionRefused $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();

            $action->cancel();
        }
    }
}
