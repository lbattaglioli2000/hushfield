<?php

namespace App\Filament\Resources\ChatReports\Tables;

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportResolution;
use App\Domain\Moderation\ReportStatus;
use App\Filament\Resources\ChatReports\Actions\ResolveReportAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChatReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['reporter', 'reportedUser', 'chatMessage', 'roomInstance.room']))
            // Open reports first ('open' sorts before 'resolved'), then newest first.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('case when status = ? then 0 else 1 end', [ReportStatus::Open->value])
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->poll('15s')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('reason'),
                TextColumn::make('reportedUser.username')
                    ->label('Reported player')
                    ->searchable(),
                TextColumn::make('chatMessage.body')
                    ->label('Message')
                    ->limit(60)
                    ->placeholder('No message'),
                TextColumn::make('reporter.username')
                    ->label('Reported by')
                    ->searchable(),
                TextColumn::make('resolution')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Reported')
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ReportStatus::class),
                SelectFilter::make('reason')
                    ->options(ReportReason::class),
                SelectFilter::make('resolution')
                    ->options(ReportResolution::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(ResolveReportAction::all())
                    ->label('Resolve')
                    ->button(),
            ]);
    }
}
