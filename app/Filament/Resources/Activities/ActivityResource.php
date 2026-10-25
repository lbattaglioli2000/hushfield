<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Read-only view of the activity log.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?string $slug = 'audit-log';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')
                    ->label('Time')
                    ->dateTime(),
                TextEntry::make('log_name')
                    ->label('Log')
                    ->badge(),
                TextEntry::make('causer')
                    ->state(fn (Activity $record): ?string => self::describe($record->causer, $record->causer_type, $record->causer_id))
                    ->placeholder('System'),
                TextEntry::make('event')
                    ->placeholder('—'),
                TextEntry::make('subject')
                    ->state(fn (Activity $record): ?string => self::describe($record->subject, $record->subject_type, $record->subject_id))
                    ->placeholder('—'),
                TextEntry::make('description'),
                KeyValueEntry::make('properties')
                    ->columnSpanFull()
                    ->state(fn (Activity $record): array => self::properties($record))
                    ->keyLabel('Property')
                    ->placeholder('No properties'),
            ]);
    }

    public static function table(Table $table): Table
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
                    ->badge(),
                TextColumn::make('event')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('causer')
                    ->state(fn (Activity $record): ?string => self::describe($record->causer, $record->causer_type, $record->causer_id))
                    ->placeholder('System'),
                TextColumn::make('subject')
                    ->state(fn (Activity $record): ?string => self::describe($record->subject, $record->subject_type, $record->subject_id))
                    ->placeholder('—'),
                TextColumn::make('properties')
                    ->state(fn (Activity $record): ?string => collect(self::properties($record))
                        ->map(fn (string $value, string $key): string => "{$key}: {$value}")
                        ->implode(' · ') ?: null)
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }

    private static function describe(?Model $model, ?string $type, ?int $id): ?string
    {
        if ($type === null) {
            return null;
        }

        $name = class_basename($type);

        return match (true) {
            $model instanceof User => "{$model->username} ({$name} #{$id})",
            default => "{$name} #{$id}",
        };
    }

    /**
     * @return array<string, string>
     */
    private static function properties(Activity $record): array
    {
        return collect($record->properties?->all() ?? [])
            ->mapWithKeys(fn (mixed $value, int|string $key): array => [(string) $key => match (true) {
                is_string($value) => $value,
                $value === null => 'null',
                default => (string) json_encode($value),
            }])
            ->all();
    }
}
