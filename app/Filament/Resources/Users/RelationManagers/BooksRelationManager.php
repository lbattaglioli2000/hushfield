<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Books\BookResource;
use App\Models\BookCopy;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The copies of books a resident holds. Books credited to them are on the Books page, filtered by author. */
class BooksRelationManager extends RelationManager
{
    protected static string $relationship = 'bookCopies';

    protected static ?string $title = 'Books';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['book.author', 'giver']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('book.title')
                    ->label('Book')
                    ->url(fn (BookCopy $record): string => BookResource::getUrl('view', ['record' => $record->book_id])),
                TextColumn::make('book.author.username')
                    ->label('Credited to')
                    ->placeholder('No author'),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('giver.username')
                    ->label('Given by')
                    ->placeholder('—'),
                IconColumn::make('on_shelf')
                    ->label('On a shelf at home')
                    ->boolean()
                    ->state(fn (BookCopy $record): bool => $record->shelf_id !== null),
                TextColumn::make('created_at')
                    ->label('Since')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
