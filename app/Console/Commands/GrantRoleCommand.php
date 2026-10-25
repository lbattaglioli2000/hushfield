<?php

namespace App\Console\Commands;

use App\Actions\Identity\ChangeStaffRole;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\StaffRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Changes a staff role without the admin panel, for the first admin or when nobody
 * who could use the panel is around. The change is audited with the console (System)
 * as the causer. The panel only admits verified emails, so promoting an unverified
 * account needs --verify-email: whoever runs this vouches for the address.
 */
#[Signature('hushfield:grant-role
    {user : Username or email of the account}
    {role : admin, moderator, or none to remove staff access}
    {--reason= : Why, for the audit log (required)}
    {--verify-email : Mark the email verified so the account can open the admin panel}')]
#[Description('Make a resident an admin or moderator, or remove their staff access')]
final class GrantRoleCommand extends Command
{
    public function handle(ChangeStaffRole $changeStaffRole): int
    {
        $login = (string) $this->argument('user');
        $roleName = strtolower((string) $this->argument('role'));
        $reason = trim((string) $this->option('reason'));

        $role = StaffRole::tryFrom($roleName);

        if ($role === null && $roleName !== 'none') {
            $this->error("Unknown role \"{$roleName}\". Use admin, moderator, or none.");

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('A reason is required for the audit log: --reason="…"');

            return self::FAILURE;
        }

        // Both columns are citext, so these match regardless of case.
        $user = User::query()->where('username', $login)->orWhere('email', $login)->first();

        if ($user === null) {
            $this->error("No account has the username or email \"{$login}\".");

            return self::FAILURE;
        }

        if ($role !== null && ! $user->hasVerifiedEmail()) {
            if (! $this->option('verify-email')) {
                $this->error("{$user->username} has not verified {$user->email}, and the admin panel requires a verified email.");
                $this->line('Ask them to verify it first, or rerun with --verify-email if you can vouch for the address.');

                return self::FAILURE;
            }

            $user->markEmailAsVerified();

            activity(AuditLog::Staff)
                ->performedOn($user)
                ->causedByAnonymous()
                ->withProperties(['email' => $user->email, 'reason' => $reason])
                ->event('staff.email_verified')
                ->log('Marked email verified from the console');

            $this->info("Marked {$user->email} verified.");
        }

        $label = match ($role) {
            StaffRole::Admin => 'an admin',
            StaffRole::Moderator => 'a moderator',
            null => 'a player',
        };

        if (! $changeStaffRole($user, $role, null, $reason)) {
            $this->info("{$user->username} is already {$label}. Nothing changed.");

            return self::SUCCESS;
        }

        $this->info("{$user->username} is now {$label}.");

        return self::SUCCESS;
    }
}
