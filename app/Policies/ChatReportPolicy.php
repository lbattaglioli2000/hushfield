<?php

namespace App\Policies;

use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Models\ChatReport;
use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Staff work the reports queue. Reports come from players, never from the panel.
 */
final class ChatReportPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, ChatReport $report): bool
    {
        return $staff->isStaff();
    }

    public function resolve(User $staff, ChatReport $report): bool
    {
        return $staff->isStaff() && $report->status === ReportStatus::Open;
    }

    /**
     * Resolving with a sanction also needs the right to sanction the reported
     * player, so a report against staff can only be dismissed.
     */
    public function resolveAs(User $staff, ChatReport $report, ReportResolution $resolution): bool
    {
        return $this->resolve($staff, $report)
            && ($resolution->sanctionType() === null || $staff->can('sanction', $report->reportedUser));
    }
}
