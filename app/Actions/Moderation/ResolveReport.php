<?php

namespace App\Actions\Moderation;

use App\Domain\Audit\AuditLog;
use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Exceptions\ModerationActionRefused;
use App\Models\ChatReport;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an open report, issuing the matching sanction. The report row is
 * locked, so two moderators resolving it at once produce one outcome, not two.
 */
final readonly class ResolveReport
{
    public function __construct(private IssueSanction $issueSanction) {}

    public function __invoke(ChatReport $report, ReportResolution $resolution, User $staff, string $reason, ?CarbonInterface $endsAt = null): ChatReport
    {
        DB::transaction(function () use ($report, $resolution, $staff, $reason, $endsAt): void {
            $locked = ChatReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReportStatus::Open) {
                throw ModerationActionRefused::reportNotOpen();
            }

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
        });

        activity(AuditLog::Moderation)
            ->performedOn($report)
            ->causedBy($staff)
            ->withProperties(['resolution' => $resolution->value, 'reason' => $reason])
            ->event('report.resolved')
            ->log("Resolved report as {$resolution->value}");

        return $report;
    }
}
