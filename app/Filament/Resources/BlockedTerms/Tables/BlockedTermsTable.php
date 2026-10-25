<?php

namespace App\Filament\Resources\BlockedTerms\Tables;

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlockedTermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('term')
            ->columns([
                TextColumn::make('term')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('match_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (BlockedTermSeverity $state): string => match ($state) {
                        BlockedTermSeverity::High => 'danger',
                        BlockedTermSeverity::Medium => 'warning',
                        BlockedTermSeverity::Low => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('match_type')
                    ->options(BlockedTermMatchType::class),
                SelectFilter::make('severity')
                    ->options(BlockedTermSeverity::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Delete one by one so BlockedTerm's deleted hook flushes the cached list.
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                ]),
            ]);
    }
}
