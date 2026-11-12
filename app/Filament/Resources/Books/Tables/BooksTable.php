<?php

namespace App\Filament\Resources\Books\Tables;

use App\Domain\Books\BookState;
use App\Models\Book;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['author', 'libraryShelf'])->withCount('copies'))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author.username')
                    ->label('Credited to')
                    ->placeholder('No author')
                    ->searchable(),
                TextColumn::make('libraryShelf.name')
                    ->label('Shelf'),
                TextColumn::make('state')
                    ->badge()
                    ->state(fn (Book $record): BookState => $record->state()),
                TextColumn::make('copies_count')
                    ->label('Copies')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('Not yet')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(BookState::class)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        BookState::Draft->value => $query->whereNull('published_at'),
                        BookState::Published->value => $query->whereNotNull('published_at')->whereNull('withdrawn_at'),
                        BookState::Withdrawn->value => $query->whereNotNull('withdrawn_at'),
                        default => $query,
                    }),
                SelectFilter::make('library_shelf_id')
                    ->label('Shelf')
                    ->relationship('libraryShelf', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
