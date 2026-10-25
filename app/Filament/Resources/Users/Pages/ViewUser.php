<?php

namespace App\Filament\Resources\Users\Pages;

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

    private const string PLAYER = 'player';

    protected function getHeaderActions(): array
    {
        return [
            $this->sanctionAction(SanctionType::Warning, 'Warn', Heroicon::OutlinedExclamationTriangle, 'gray'),
            $this->sanctionAction(SanctionType::Mute, 'Mute', Heroicon::OutlinedSpeakerXMark, 'warning'),
            $this->sanctionAction(SanctionType::Ban, 'Ban', Heroicon::OutlinedNoSymbol, 'danger'),
            $this->staffRoleAction(),
        ];
    }

    /**
     * Admins promote players to staff or demote staff. There is no action class
     * for this yet, so the change and its audit entry are written here.
     */
    private function staffRoleAction(): Action
    {
        return Action::make('staffRole')
            ->label('Staff role')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->authorize('manageRole')
            ->fillForm(fn (User $record): array => ['role' => $record->role->value ?? self::PLAYER])
            ->schema([
                Select::make('role')
                    ->options([self::PLAYER => 'Player (no staff access)', ...collect(StaffRole::cases())->mapWithKeys(fn (StaffRole $role): array => [$role->value => ucfirst($role->value)])->all()])
                    ->selectablePlaceholder(false)
                    ->required(),
                ModerationForm::fields(null)[0],
            ])
            ->action(function (User $record, array $data): void {
                $from = $record->role;
                $to = StaffRole::tryFrom((string) $data['role']);

                $record->forceFill(['role' => $to])->save();

                activity('staff')
                    ->performedOn($record)
                    ->causedBy(Staff::current())
                    ->withProperties(['from' => $from?->value, 'to' => $to?->value, 'reason' => ModerationForm::reason($data)])
                    ->event('staff.role_changed')
                    ->log('Changed staff role');
            })
            ->successNotificationTitle('Staff role updated');
    }

    private function sanctionAction(SanctionType $type, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($type->value)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize('sanction')
            ->modalHeading(fn (User $record): string => "{$label} {$record->username}")
            ->schema(ModerationForm::fields($type))
            ->action(function (User $record, array $data, IssueSanction $issueSanction) use ($type): void {
                $issueSanction($record, $type, ModerationForm::reason($data), ModerationForm::endsAt($data), Staff::current());
            })
            ->successNotificationTitle("{$label} issued")
            ->after(fn () => $this->dispatch('refresh-sanctions'));
    }
}
