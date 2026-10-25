<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Domain\Audit\AuditLog;
use App\Filament\Resources\Activities\ActivityFormat;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('log_name')
                    ->label('Log')
                    ->formatStateUsing(fn (?string $state): ?string => ActivityFormat::logName($state))
                    ->badge(),
                TextColumn::make('event')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('causer')
                    ->state(fn (Activity $record): ?string => ActivityFormat::causer($record))
                    ->placeholder('System'),
                TextColumn::make('subject')
                    ->state(fn (Activity $record): ?string => ActivityFormat::subject($record))
                    ->placeholder('—'),
                TextColumn::make('properties')
                    ->state(fn (Activity $record): ?string => collect(ActivityFormat::properties($record))
                        ->map(fn (string $value, string $key): string => "{$key}: {$value}")
                        ->implode(' · ') ?: null)
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(AuditLog::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
