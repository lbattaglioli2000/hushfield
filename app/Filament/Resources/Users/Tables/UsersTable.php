<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Identity\StaffRole;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('age_bracket')
                    ->label('Age')
                    ->state(fn (User $record): string => $record->ageBracket()),
                TextColumn::make('role')
                    ->badge()
                    ->placeholder('Player'),
                IconColumn::make('banned')
                    ->label('Banned')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->isBanned()),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(StaffRole::class),
                TernaryFilter::make('banned')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('banned_until', '>', now()),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('banned_until')->orWhere('banned_until', '<=', now())),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
