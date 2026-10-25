<?php

namespace App\Filament\Resources\ChatReports\Schemas;

use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportStatus;
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
                            ->badge()
                            ->color(fn (ReportStatus $state): string => $state === ReportStatus::Open ? 'danger' : 'gray'),
                        TextEntry::make('reason')
                            ->formatStateUsing(fn (ReportReason $state): string => $state->label()),
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
                            ->state(fn (ChatReport $record): Collection => self::context($record)),
                    ]),
            ]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public static function context(ChatReport $report): Collection
    {
        $message = $report->chatMessage;

        if ($message === null) {
            return new Collection;
        }

        $base = fn () => ChatMessage::query()
            ->with('user')
            ->where('room_instance_id', $message->room_instance_id);

        $before = $base()->where('id', '<', $message->id)->orderByDesc('id')->limit(self::CONTEXT)->get()->reverse();
        $after = $base()->where('id', '>', $message->id)->orderBy('id')->limit(self::CONTEXT)->get();

        return $before->toBase()
            ->push($message->loadMissing('user'))
            ->merge($after)
            ->values();
    }
}
