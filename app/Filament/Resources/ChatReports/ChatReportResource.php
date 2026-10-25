<?php

namespace App\Filament\Resources\ChatReports;

use App\Domain\Moderation\ReportStatus;
use App\Filament\Resources\ChatReports\Pages\ListChatReports;
use App\Filament\Resources\ChatReports\Pages\ViewChatReport;
use App\Filament\Resources\ChatReports\Schemas\ChatReportInfolist;
use App\Filament\Resources\ChatReports\Tables\ChatReportsTable;
use App\Models\ChatReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ChatReportResource extends Resource
{
    protected static ?string $model = ChatReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Reports queue';

    protected static ?string $modelLabel = 'report';

    public static function getNavigationBadge(): ?string
    {
        $open = ChatReport::query()->where('status', ReportStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChatReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChatReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatReports::route('/'),
            'view' => ViewChatReport::route('/{record}'),
        ];
    }
}
