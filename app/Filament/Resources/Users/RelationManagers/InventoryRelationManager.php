<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class InventoryRelationManager extends RelationManager
{
    protected static string $relationship = 'inventory';

    protected static ?string $title = 'Inventory';

    protected static bool $isLazy = false;

    /**
     * Re-renders the table after an item is granted from the page header.
     */
    #[On('refresh-economy')]
    public function refreshEconomy(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'granter']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('item.name')
                    ->label('Item'),
                TextColumn::make('item.slot')
                    ->label('Slot')
                    ->badge(),
                TextColumn::make('item.rarity')
                    ->label('Rarity')
                    ->badge(),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('granter.username')
                    ->label('Granted by')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Acquired')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
