<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Domain\Books\BookCover;
use App\Models\Book;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['lg' => 3])
            ->components([
                Section::make('Text')
                    ->description('Plain text. Leave a blank line between paragraphs. The game splits it into pages to fit the screen.')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(Book::MAX_TITLE),
                        Textarea::make('body')
                            ->label('Text')
                            ->required()
                            ->maxLength(Book::MAX_BODY)
                            ->rows(24),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make([
                    Section::make('Book')
                        ->schema([
                            Select::make('author_id')
                                ->label('Credited to')
                                ->helperText('A resident named as its author on the spine. Leave empty for no author.')
                                ->relationship('author', 'username')
                                ->searchable()
                                ->preload(false),
                            Select::make('library_shelf_id')
                                ->label('Library shelf')
                                ->helperText('The category it is shelved under in the library.')
                                ->relationship('libraryShelf', 'name', fn ($query) => $query->orderBy('position'))
                                ->required(),
                            Select::make('cover')
                                ->label('Cover colour')
                                ->options(BookCover::class)
                                ->default(BookCover::Moss)
                                ->required(),
                        ]),
                    Section::make('Published')
                        ->description('Changes to a published book show in the game straight away, so they are checked against the blocked terms list as publishing is.')
                        ->visible(fn (?Book $record): bool => $record?->isReadable() ?? false)
                        ->schema([
                            Checkbox::make('confirmed')
                                ->label('If it now has a link, email or phone number, I checked it: save anyway.')
                                ->dehydrated(),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]);
    }
}
