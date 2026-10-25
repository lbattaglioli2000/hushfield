<?php

namespace App\Filament\Resources\BlockedTerms;

use App\Filament\Resources\BlockedTerms\Pages\CreateBlockedTerm;
use App\Filament\Resources\BlockedTerms\Pages\EditBlockedTerm;
use App\Filament\Resources\BlockedTerms\Pages\ListBlockedTerms;
use App\Filament\Resources\BlockedTerms\Schemas\BlockedTermForm;
use App\Filament\Resources\BlockedTerms\Tables\BlockedTermsTable;
use App\Models\BlockedTerm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BlockedTermResource extends Resource
{
    protected static ?string $model = BlockedTerm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'term';

    public static function form(Schema $schema): Schema
    {
        return BlockedTermForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BlockedTermsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlockedTerms::route('/'),
            'create' => CreateBlockedTerm::route('/create'),
            'edit' => EditBlockedTerm::route('/{record}/edit'),
        ];
    }
}
