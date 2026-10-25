<?php

namespace App\Filament\Resources\ChatReports\Actions;

use App\Actions\Moderation\ResolveReport;
use App\Domain\Moderation\ReportResolution;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\ChatReport;
use Filament\Actions\Action;

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
        return Action::make("resolve_{$resolution->value}")
            ->label($resolution->verb())
            ->icon($resolution->getIcon())
            ->color($resolution->getColor())
            ->authorize('resolveAs', [$resolution])
            ->modalHeading(fn (ChatReport $record): string => $resolution === ReportResolution::Dismissed
                ? "Dismiss report #{$record->id}"
                : "{$resolution->verb()} {$record->reportedUser->username}")
            ->schema(ModerationForm::fields($resolution->sanctionType()))
            ->action(function (ChatReport $record, array $data, ResolveReport $resolveReport, Action $action) use ($resolution): void {
                ModerationForm::runOrNotify($action, fn () => $resolveReport($record, $resolution, Staff::current(), ModerationForm::reason($data), ModerationForm::endsAt($data)));
            })
            ->successNotificationTitle("Report resolved: {$resolution->value}");
    }
}
