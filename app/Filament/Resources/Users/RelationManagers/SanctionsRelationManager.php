<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Actions\Moderation\LiftSanction;
use App\Domain\Moderation\SanctionType;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\Sanction;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class SanctionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sanctions';

    protected static ?string $title = 'Sanctions history';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Re-renders the table after a sanction is issued from the page header.
     */
    #[On('refresh-sanctions')]
    public function refreshSanctions(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['issuer']))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('reason')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('starts_at')
                    ->label('Issued')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->state(fn (Sanction $record): ?string => match (true) {
                        $record->type === SanctionType::Warning => null,
                        $record->ends_at === null => 'Never',
                        default => $record->ends_at->toDayDateTimeString(),
                    })
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Sanction $record): string => match (true) {
                        $record->lifted_at !== null => 'Lifted',
                        $record->isActive() => 'Active',
                        default => 'Expired',
                    })
                    ->color(fn (string $state): string => $state === 'Active' ? 'danger' : 'gray'),
                TextColumn::make('issuer.username')
                    ->label('Issued by')
                    ->placeholder('System'),
            ])
            ->recordActions([
                Action::make('lift')
                    ->label('Lift')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('success')
                    ->authorize('lift')
                    ->modalHeading('Lift sanction')
                    ->schema([ModerationForm::reasonField()])
                    ->action(function (Sanction $record, array $data, LiftSanction $liftSanction): void {
                        $liftSanction($record, Staff::current(), ModerationForm::reason($data));
                    })
                    ->successNotificationTitle('Sanction lifted'),
            ]);
    }
}
