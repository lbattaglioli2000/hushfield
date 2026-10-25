<?php

namespace App\Actions\Moderation;

use App\Domain\Audit\AuditLog;
use App\Domain\Moderation\SanctionType;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class LiftSanction
{
    public function __invoke(Sanction $sanction, User $staff, string $reason): Sanction
    {
        DB::transaction(function () use ($sanction, $staff): void {
            $sanction->forceFill(['lifted_at' => now(), 'lifted_by' => $staff->id])->save();

            if ($sanction->type === SanctionType::Ban) {
                $sanction->user->syncBannedUntil();
            }
        });

        activity(AuditLog::Moderation)
            ->performedOn($sanction->user)
            ->causedBy($staff)
            ->withProperties(['sanction_id' => $sanction->id, 'type' => $sanction->type->value, 'reason' => $reason])
            ->event('sanction.lifted')
            ->log("Lifted {$sanction->type->value}");

        return $sanction;
    }
}
