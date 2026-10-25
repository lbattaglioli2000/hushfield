<?php

namespace App\Actions\Moderation;

use App\Actions\Presence\EndWorldSession;
use App\Domain\Moderation\SanctionType;
use App\Events\Moderation\PlayerWarned;
use App\Events\Moderation\WorldAccessRevoked;
use App\Models\ChatReport;
use App\Models\Sanction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mutes, bans, or warns a player. A ban takes effect immediately: the player's
 * client is told through its private channel and removed from its instance.
 */
final readonly class IssueSanction
{
    public function __construct(private EndWorldSession $endWorldSession) {}

    public function __invoke(User $target, SanctionType $type, string $reason, ?CarbonInterface $endsAt, User $staff, ?ChatReport $report = null): Sanction
    {
        $sanction = DB::transaction(function () use ($target, $type, $reason, $endsAt, $staff, $report): Sanction {
            $sanction = Sanction::query()->create([
                'user_id' => $target->id,
                'type' => $type,
                'reason' => $reason,
                'starts_at' => now(),
                'ends_at' => $type === SanctionType::Warning ? now() : $endsAt,
                'issued_by' => $staff->id,
                'chat_report_id' => $report?->id,
            ]);

            if ($type === SanctionType::Ban) {
                $target->forceFill(['banned_until' => $endsAt ?? User::PERMANENT_BAN_UNTIL])->save();
            }

            return $sanction;
        });

        activity('moderation')
            ->performedOn($target)
            ->causedBy($staff)
            ->withProperties(['type' => $type->value, 'reason' => $reason, 'ends_at' => $endsAt?->toIso8601String(), 'report_id' => $report?->id])
            ->event("sanction.{$type->value}")
            ->log("Issued {$type->value}");

        $token = $target->worldSession()->value('session_token');

        // The durable state is final before anything is broadcast; a websocket
        // outage only delays the client notice (the session token is already dead).
        if ($type === SanctionType::Ban) {
            ($this->endWorldSession)($target->refresh());

            if ($token !== null) {
                rescue(fn () => broadcast(new WorldAccessRevoked($token)));
            }
        }

        if ($type === SanctionType::Warning && $token !== null) {
            rescue(fn () => broadcast(new PlayerWarned($token)));
        }

        return $sanction;
    }
}
