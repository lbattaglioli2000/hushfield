<?php

namespace App\Policies;

use App\Domain\Moderation\ReportStatus;
use App\Models\ChatReport;
use App\Models\User;

/**
 * Staff work the reports queue. Reports come from players, never from the panel.
 */
final class ChatReportPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, ChatReport $report): bool
    {
        return $staff->isStaff();
    }

    public function create(User $staff): bool
    {
        return false;
    }

    public function update(User $staff, ChatReport $report): bool
    {
        return false;
    }

    public function delete(User $staff, ChatReport $report): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }

    public function resolve(User $staff, ChatReport $report): bool
    {
        return $staff->isStaff() && $report->status === ReportStatus::Open;
    }
}
