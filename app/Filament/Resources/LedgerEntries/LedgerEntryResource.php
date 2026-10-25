<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use App\Models\LedgerEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of every coin movement.
 */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Economy';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Coin ledger';

    protected static ?string $modelLabel = 'ledger entry';

    protected static ?string $pluralModelLabel = 'coin ledger';

    protected static ?string $slug = 'ledger';

    public static function table(Table $table): Table
    {
        return LedgerEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
        ];
    }
}
