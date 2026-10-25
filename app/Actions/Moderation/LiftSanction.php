<?php

namespace App\Actions\Moderation;

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
                $remaining = $sanction->user->sanctions()->active()->where('type', SanctionType::Ban)->get();
                $sanction->user->forceFill([
                    'banned_until' => $remaining->isEmpty() ? null : ($remaining->contains(fn (Sanction $ban): bool => $ban->ends_at === null)
                        ? User::PERMANENT_BAN_UNTIL
                        : $remaining->max('ends_at')),
                ])->save();
            }
        });

        activity('moderation')
            ->performedOn($sanction->user)
            ->causedBy($staff)
            ->withProperties(['sanction_id' => $sanction->id, 'type' => $sanction->type->value, 'reason' => $reason])
            ->event('sanction.lifted')
            ->log("Lifted {$sanction->type->value}");

        return $sanction;
    }
}
