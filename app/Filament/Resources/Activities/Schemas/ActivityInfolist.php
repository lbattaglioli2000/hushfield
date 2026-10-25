<?php

namespace App\Filament\Resources\Activities\Schemas;

use App\Filament\Resources\Activities\ActivityFormat;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')
                    ->label('Time')
                    ->dateTime(),
                TextEntry::make('log_name')
                    ->label('Log')
                    ->formatStateUsing(fn (?string $state): ?string => ActivityFormat::logName($state))
                    ->badge(),
                TextEntry::make('causer')
                    ->state(fn (Activity $record): ?string => ActivityFormat::causer($record))
                    ->placeholder('System'),
                TextEntry::make('event')
                    ->placeholder('—'),
                TextEntry::make('subject')
                    ->state(fn (Activity $record): ?string => ActivityFormat::subject($record))
                    ->placeholder('—'),
                TextEntry::make('description'),
                KeyValueEntry::make('properties')
                    ->columnSpanFull()
                    ->state(fn (Activity $record): array => ActivityFormat::properties($record))
                    ->keyLabel('Property')
                    ->placeholder('No properties'),
            ]);
    }
}
