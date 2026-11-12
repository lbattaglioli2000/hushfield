<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Domain\Books\BookState;
use App\Filament\Resources\Users\UserResource;
use App\Models\Book;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** What moderators read of a book: everything, without editing it. */
class BookInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['lg' => 3])
            ->components([
                Section::make('Text')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('body')
                            ->label('Text')
                            ->prose()
                            ->formatStateUsing(fn (string $state): string => nl2br(e($state)))
                            ->html(),
                    ])
                    ->columnSpan(['lg' => 2]),
                Section::make('Book')
                    ->schema([
                        TextEntry::make('state')
                            ->badge()
                            ->state(fn (Book $record): BookState => $record->state()),
                        TextEntry::make('author.username')
                            ->label('Credited to')
                            ->placeholder('No author')
                            ->url(fn (Book $record): ?string => $record->author_id === null ? null : UserResource::getUrl('view', ['record' => $record->author_id])),
                        TextEntry::make('libraryShelf.name')
                            ->label('Library shelf'),
                        TextEntry::make('cover')
                            ->label('Cover colour'),
                        TextEntry::make('published_at')
                            ->dateTime()
                            ->placeholder('Not yet'),
                        TextEntry::make('withdrawn_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columnSpan(['lg' => 1]),
            ]);
    }
}
