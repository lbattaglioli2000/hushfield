<?php

namespace App\Actions\Moderation;

use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Models\ChatReport;
use App\Models\User;
use Carbon\CarbonInterface;

final readonly class ResolveReport
{
    public function __construct(private IssueSanction $issueSanction) {}

    public function __invoke(ChatReport $report, ReportResolution $resolution, User $staff, string $reason, ?CarbonInterface $endsAt = null): ChatReport
    {
        $type = $resolution->sanctionType();

        if ($type !== null) {
            ($this->issueSanction)($report->reportedUser, $type, $reason, $endsAt, $staff, $report);
        }

        $report->forceFill([
            'status' => ReportStatus::Resolved,
            'resolution' => $resolution,
            'resolved_by' => $staff->id,
            'resolved_at' => now(),
        ])->save();

        activity('moderation')
            ->performedOn($report)
            ->causedBy($staff)
            ->withProperties(['resolution' => $resolution->value, 'reason' => $reason])
            ->event('report.resolved')
            ->log("Resolved report as {$resolution->value}");

        return $report;
    }
}
