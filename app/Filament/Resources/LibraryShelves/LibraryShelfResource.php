<?php

namespace App\Filament\Resources\LibraryShelves;

use App\Domain\Books\Library;
use App\Filament\Resources\LibraryShelves\Pages\CreateLibraryShelf;
use App\Filament\Resources\LibraryShelves\Pages\EditLibraryShelf;
use App\Filament\Resources\LibraryShelves\Pages\ListLibraryShelves;
use App\Models\LibraryShelf;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The library's categories, such as "New arrivals". Each needs a shelf on the
 * library map (a fixture of kind "library" named its slug) before players can
 * reach its books: see docs/art/maps-in-tiled.md.
 */
class LibraryShelfResource extends Resource
{
    protected static ?string $model = LibraryShelf::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'World';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Library shelves';

    protected static ?string $modelLabel = 'library shelf';

    protected static ?string $pluralModelLabel = 'library shelves';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->helperText('What players see as the shelf\'s title, such as "Town history".')
                ->required()
                ->maxLength(40),
            TextInput::make('slug')
                ->helperText('The name the library map gives this shelf in Tiled. It can\'t change once created.')
                ->required()
                ->maxLength(40)
                ->alphaDash()
                ->regex('/^[a-z0-9-]+$/')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            TextInput::make('position')
                ->label('Order')
                ->helperText('Lower comes first in the admin\'s lists.')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(999)
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $onMaps = app(Library::class)->shelvesOnMaps();

        return $table
            ->defaultSort('position')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['books', 'books as published_count' => fn ($books) => $books->readable()]))
            ->description('Each category needs a shelf on the library map before players can reach its books. Add the category here first, then ask for a shelf named its slug on the map.')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (LibraryShelf $record): string => $record->slug)
                    ->searchable(['name', 'slug']),
                IconColumn::make('on_map')
                    ->label('Shelf on the map')
                    ->boolean()
                    ->state(fn (LibraryShelf $record): bool => in_array($record->slug, $onMaps, true))
                    ->tooltip(fn (LibraryShelf $record): ?string => in_array($record->slug, $onMaps, true) ? null : "No map has a library shelf named \"{$record->slug}\" yet, so players can't reach these books."),
                TextColumn::make('published_count')
                    ->label('Published')
                    ->numeric(),
                TextColumn::make('books_count')
                    ->label('All books')
                    ->numeric(),
                TextColumn::make('position')
                    ->label('Order')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLibraryShelves::route('/'),
            'create' => CreateLibraryShelf::route('/create'),
            'edit' => EditLibraryShelf::route('/{record}/edit'),
        ];
    }
}
