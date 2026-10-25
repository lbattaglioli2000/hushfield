<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Identity\ChangeStaffRole;
use App\Actions\Moderation\IssueSanction;
use App\Domain\Identity\StaffRole;
use App\Domain\Moderation\SanctionType;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->sanctionAction(SanctionType::Warning),
            $this->sanctionAction(SanctionType::Mute),
            $this->sanctionAction(SanctionType::Ban),
            $this->staffRoleAction(),
        ];
    }

    /**
     * Admins promote players to staff or demote staff. An empty role means player.
     */
    private function staffRoleAction(): Action
    {
        return Action::make('staffRole')
            ->label('Staff role')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->authorize('manageRole')
            ->fillForm(fn (User $record): array => ['role' => $record->role?->value])
            ->schema([
                Select::make('role')
                    ->options(StaffRole::class)
                    ->placeholder('Player (no staff access)'),
                ModerationForm::reasonField(),
            ])
            ->action(function (User $record, array $data, ChangeStaffRole $changeStaffRole): void {
                $role = $data['role'] ?? null;

                $changeStaffRole(
                    $record,
                    $role instanceof StaffRole ? $role : StaffRole::tryFrom(is_string($role) ? $role : ''),
                    Staff::current(),
                    ModerationForm::reason($data),
                );
            })
            ->successNotificationTitle('Staff role updated');
    }

    private function sanctionAction(SanctionType $type): Action
    {
        return Action::make($type->value)
            ->label($type->verb())
            ->icon($type->getIcon())
            ->color($type->getColor())
            ->authorize('sanction')
            ->modalHeading(fn (User $record): string => "{$type->verb()} {$record->username}")
            ->schema(ModerationForm::fields($type))
            ->action(function (User $record, array $data, IssueSanction $issueSanction, Action $action) use ($type): void {
                ModerationForm::runOrNotify($action, fn () => $issueSanction($record, $type, ModerationForm::reason($data), ModerationForm::endsAt($data), Staff::current()));
            })
            ->successNotificationTitle("{$type->getLabel()} issued")
            ->after(fn () => $this->dispatch('refresh-sanctions'));
    }
}
