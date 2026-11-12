<?php

namespace App\Filament\Resources\Books\RelationManagers;

use App\Actions\Books\GiveBookCopy;
use App\Filament\Support\ModerationForm;
use App\Filament\Support\Staff;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who holds a copy of the book, and how they got it. Staff give copies here,
 * one per resident at most.
 */
class CopiesRelationManager extends RelationManager
{
    protected static string $relationship = 'copies';

    protected static ?string $title = 'Copies';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Book $book */
        $book = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'giver']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.username')
                    ->label('Held by')
                    ->searchable(),
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
            ])
            ->headerActions([
                Action::make('giveCopy')
                    ->label('Give a copy')
                    ->icon(Heroicon::OutlinedGift)
                    ->authorize(fn (): bool => auth()->user()?->can('giveCopy', $book) ?? false)
                    ->visible($book->isReadable())
                    ->modalDescription('The resident keeps it for good. Each resident holds one copy of a book at most.')
                    ->schema([
                        Select::make('user_id')
                            ->label('Resident')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()->where('username', 'ilike', "{$search}%")->limit(20)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('username'))
                            ->required(),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (array $data, Action $action, GiveBookCopy $give) use ($book): void {
                        ModerationForm::runOrNotify($action, fn () => $give($book, User::query()->whereKey($data['user_id'])->firstOrFail(), Staff::current(), (string) $data['reason']));
                    })
                    ->successNotificationTitle('Copy given.'),
            ]);
    }
}
