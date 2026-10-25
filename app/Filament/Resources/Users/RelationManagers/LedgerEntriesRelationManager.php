<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Coins';

    protected static bool $isLazy = false;

    /**
     * Re-renders the table after coins or items change from the page header.
     */
    #[On('refresh-economy')]
    public function refreshEconomy(): void {}

    public function table(Table $table): Table
    {
        return LedgerEntriesTable::configure($table, showUser: false);
    }
}
