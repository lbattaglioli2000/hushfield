<?php

namespace App\Filament\Resources\Items\Tables;

use App\Domain\Items\ItemRarity;
use App\Domain\Items\ItemSlot;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('owners'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn ($record): string => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('slot')
                    ->badge()
                    ->sortable(),
                TextColumn::make('rarity')
                    ->badge()
                    ->sortable(),
                TextColumn::make('price')
                    ->numeric()
                    ->suffix(' coins')
                    ->sortable(),
                IconColumn::make('in_shop')
                    ->label('In shop')
                    ->boolean(),
                IconColumn::make('members_only')
                    ->label('Members')
                    ->boolean(),
                TextColumn::make('owners_count')
                    ->label('Owned by')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('slot')
                    ->options(ItemSlot::class),
                SelectFilter::make('rarity')
                    ->options(ItemRarity::class),
                TernaryFilter::make('in_shop')
                    ->label('In the shop'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
