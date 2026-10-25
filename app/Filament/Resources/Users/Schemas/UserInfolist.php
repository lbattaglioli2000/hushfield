<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Economy\Wallet;
use App\Models\ChatMessage;
use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

class UserInfolist
{
    public const int RECENT_MESSAGES = 20;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('username'),
                        TextEntry::make('email'),
                        TextEntry::make('age_bracket')
                            ->label('Age bracket')
                            ->state(fn (User $record): string => $record->ageBracket()),
                        TextEntry::make('role')
                            ->badge()
                            ->placeholder('Player'),
                        TextEntry::make('chat_filter_level')
                            ->label('Chat filter'),
                        IconEntry::make('email_verified')
                            ->label('Email verified')
                            ->boolean()
                            ->state(fn (User $record): bool => $record->hasVerifiedEmail()),
                        TextEntry::make('banned_until')
                            ->label('Banned until')
                            ->state(fn (User $record): ?string => match (true) {
                                ! $record->isBanned() => null,
                                $record->isPermanentlyBanned() => 'Permanently',
                                default => $record->banned_until?->toDayDateTimeString(),
                            })
                            ->placeholder('Not banned')
                            ->color('danger'),
                        IconEntry::make('muted')
                            ->label('Muted')
                            ->boolean()
                            ->state(fn (User $record): bool => $record->isMuted()),
                        TextEntry::make('worldSession.last_seen_at')
                            ->label('In the world, last seen')
                            ->since()
                            ->placeholder('Offline'),
                        TextEntry::make('created_at')
                            ->label('Joined')
                            ->dateTime(),
                        TextEntry::make('home')
                            ->label('Residence')
                            ->state(fn (User $record): ?string => $record->home === null ? null : $record->home->visitors->getLabel().' · '.$record->home->furniture()->count().' pieces placed')
                            ->placeholder('Not moved in'),
                        TextEntry::make('friends_count')
                            ->label('Friends')
                            ->numeric()
                            ->state(fn (User $record): int => $record->friends()->count()),
                        TextEntry::make('coins')
                            ->label('Coins')
                            ->numeric()
                            ->state(fn (User $record): int => app(Wallet::class)->balance($record)),
                    ]),
                Section::make('Recent chat')
                    ->description('The '.self::RECENT_MESSAGES.' most recent messages, newest first.')
                    ->schema([
                        RepeatableEntry::make('recent_messages')
                            ->hiddenLabel()
                            ->state(fn (User $record): Collection => $record->chatMessages()
                                ->with('roomInstance.room')
                                ->latest('id')
                                ->limit(self::RECENT_MESSAGES)
                                ->get())
                            ->placeholder('No messages.')
                            ->table([
                                TableColumn::make('Sent'),
                                TableColumn::make('Room'),
                                TableColumn::make('Message'),
                                TableColumn::make('Shown as'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->dateTime(),
                                TextEntry::make('room')
                                    ->state(fn (ChatMessage $record): string => $record->roomInstance->label()),
                                TextEntry::make('body'),
                                TextEntry::make('filtered_body')
                                    ->color(fn (ChatMessage $record): ?string => $record->was_filtered ? 'warning' : null),
                            ]),
                    ]),
            ]);
    }
}
