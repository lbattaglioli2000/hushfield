<?php

namespace App\Filament\Resources\ChatReports\Schemas;

use App\Filament\Resources\Users\UserResource;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class ChatReportInfolist
{
    /** Messages shown before and after the reported one. */
    public const int CONTEXT = 10;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Report')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('reason'),
                        TextEntry::make('reportedUser.username')
                            ->label('Reported player')
                            ->url(fn (ChatReport $record): string => UserResource::getUrl('view', ['record' => $record->reported_user_id])),
                        TextEntry::make('reporter.username')
                            ->label('Reported by')
                            ->url(fn (ChatReport $record): string => UserResource::getUrl('view', ['record' => $record->reporter_id])),
                        TextEntry::make('room')
                            ->state(fn (ChatReport $record): ?string => ($record->chatMessage->roomInstance ?? $record->roomInstance)?->label())
                            ->placeholder('Unknown'),
                        TextEntry::make('created_at')
                            ->label('Reported at')
                            ->dateTime(),
                        TextEntry::make('resolution')
                            ->badge()
                            ->placeholder('Not resolved'),
                        TextEntry::make('resolver.username')
                            ->label('Resolved by')
                            ->placeholder('—'),
                    ]),
                Section::make('Conversation')
                    ->description('The reported message, highlighted, with up to '.self::CONTEXT.' messages before and after it in the same instance.')
                    ->schema([
                        ViewEntry::make('context')
                            ->hiddenLabel()
                            ->view('filament.reports.message-context')
                            ->state(fn (ChatReport $record): Collection => $record->chatMessage === null
                                ? new Collection
                                : ChatMessage::around($record->chatMessage, self::CONTEXT)),
                    ]),
            ]);
    }
}
