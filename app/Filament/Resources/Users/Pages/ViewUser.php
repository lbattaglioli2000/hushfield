<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Economy\AdjustCoins;
use App\Actions\Housing\ResetHome;
use App\Actions\Identity\ChangeStaffRole;
use App\Actions\Items\GrantItem;
use App\Actions\Moderation\IssueSanction;
use App\Domain\Identity\StaffRole;
use App\Domain\Moderation\SanctionType;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\Item;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
            $this->resetHomeAction(),
            ActionGroup::make([
                $this->adjustCoinsAction(),
                $this->grantItemAction(),
            ])
                ->label('Coins & items')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * For a home arrangement that breaks the rules: furniture back to inventory, door closed.
     */
    private function resetHomeAction(): Action
    {
        return Action::make('resetHome')
            ->label('Reset home')
            ->icon(Heroicon::OutlinedHomeModern)
            ->color('warning')
            ->authorize('resetHome')
            ->modalHeading(fn (User $record): string => "Reset {$record->username}'s residence")
            ->modalDescription('Puts away every piece of furniture and closes the home to visitors. The resident keeps their items.')
            ->schema([ModerationForm::reasonField()])
            ->action(function (User $record, array $data, ResetHome $resetHome): void {
                if ($record->home !== null) {
                    $resetHome($record->home, Staff::current(), ModerationForm::reason($data));
                }
            })
            ->successNotificationTitle('Home reset');
    }

    /**
     * Admins add or remove coins. Written to the ledger with the reason as its note.
     */
    private function adjustCoinsAction(): Action
    {
        return Action::make('adjustCoins')
            ->label('Adjust coins')
            ->icon(Heroicon::OutlinedBanknotes)
            ->authorize('manageEconomy')
            ->modalHeading(fn (User $record): string => "Adjust {$record->username}'s coins")
            ->schema([
                TextInput::make('amount')
                    ->integer()
                    ->required()
                    ->notIn(['0'])
                    ->minValue(-1_000_000)
                    ->maxValue(1_000_000)
                    ->helperText('Positive adds coins, negative removes them. A balance cannot go below zero.'),
                ModerationForm::reasonField(),
            ])
            ->action(function (User $record, array $data, AdjustCoins $adjustCoins, Action $action): void {
                ModerationForm::runOrNotify($action, fn () => $adjustCoins($record, (int) $data['amount'], Staff::current(), ModerationForm::reason($data)));
            })
            ->successNotificationTitle('Coins adjusted')
            ->after(fn () => $this->dispatch('refresh-economy'));
    }

    /**
     * Admins give any catalog item, including ones not in the shop.
     */
    private function grantItemAction(): Action
    {
        return Action::make('grantItem')
            ->label('Grant item')
            ->icon(Heroicon::OutlinedGift)
            ->authorize('manageEconomy')
            ->modalHeading(fn (User $record): string => "Grant {$record->username} an item")
            ->schema([
                Select::make('item_id')
                    ->label('Item')
                    // A resident may own several of one item, so anything can be granted again.
                    ->options(fn (): array => Item::query()
                        ->orderBy('name')
                        ->orderBy('variant_label')
                        ->get()
                        ->mapWithKeys(fn (Item $item): array => [$item->id => $item->label()])
                        ->all())
                    ->searchable()
                    ->required(),
                ModerationForm::reasonField(),
            ])
            ->action(function (User $record, array $data, GrantItem $grantItem, Action $action): void {
                ModerationForm::runOrNotify($action, fn () => $grantItem($record, Item::query()->findOrFail((int) $data['item_id']), Staff::current(), ModerationForm::reason($data)));
            })
            ->successNotificationTitle('Item granted')
            ->after(fn () => $this->dispatch('refresh-economy'));
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
