<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

use App\Domain\Economy\LedgerReason;
use App\Filament\Resources\Users\UserResource;
use App\Models\LedgerEntry;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ledger as a table, shared by the ledger page and the coins tab on a resident's page.
 */
class LedgerEntriesTable
{
    public static function configure(Table $table, bool $showUser = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'item', 'actor']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.username')
                    ->label('Resident')
                    ->searchable()
                    ->url(fn (LedgerEntry $record): string => UserResource::getUrl('view', ['record' => $record->user_id]))
                    ->visible($showUser),
                TextColumn::make('amount')
                    ->numeric()
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').number_format($state))
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->summarize(Sum::make()->label('Net'))
                    ->sortable(),
                TextColumn::make('reason')
                    ->badge(),
                TextColumn::make('item.name')
                    ->label('Item')
                    ->placeholder('—'),
                TextColumn::make('actor.username')
                    ->label('By staff')
                    ->placeholder('—'),
                TextColumn::make('note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
                TextColumn::make('idempotency_key')
                    ->label('Key')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(LedgerReason::class),
                Filter::make('debits')
                    ->label('Debits only')
                    ->query(fn (Builder $query) => $query->where('amount', '<', 0)),
            ]);
    }
}
