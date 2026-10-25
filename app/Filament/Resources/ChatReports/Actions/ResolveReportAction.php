<?php

namespace App\Filament\Resources\ChatReports\Actions;

use App\Actions\Moderation\ResolveReport;
use App\Domain\Moderation\ReportResolution;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\ChatReport;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Dismiss, warn, mute, or ban from a report, through ResolveReport.
 */
final class ResolveReportAction
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return array_map(self::make(...), ReportResolution::cases());
    }

    public static function make(ReportResolution $resolution): Action
    {
        [$label, $icon, $color] = match ($resolution) {
            ReportResolution::Dismissed => ['Dismiss', Heroicon::OutlinedXMark, 'gray'],
            ReportResolution::Warned => ['Warn', Heroicon::OutlinedExclamationTriangle, 'info'],
            ReportResolution::Muted => ['Mute', Heroicon::OutlinedSpeakerXMark, 'warning'],
            ReportResolution::Banned => ['Ban', Heroicon::OutlinedNoSymbol, 'danger'],
        };

        return Action::make("resolve_{$resolution->value}")
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize('resolve')
            ->modalHeading(fn (ChatReport $record): string => $resolution === ReportResolution::Dismissed
                ? "Dismiss report #{$record->id}"
                : "{$label} {$record->reportedUser->username}")
            ->schema(ModerationForm::fields($resolution->sanctionType()))
            ->action(function (ChatReport $record, array $data, ResolveReport $resolveReport) use ($resolution): void {
                $resolveReport($record, $resolution, Staff::current(), ModerationForm::reason($data), ModerationForm::endsAt($data));
            })
            ->successNotificationTitle("Report resolved: {$resolution->value}");
    }
}
