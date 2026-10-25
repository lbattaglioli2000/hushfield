<?php

namespace App\Filament\Widgets;

use App\Domain\Presence\InstanceMembers;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\RoomInstance;
use App\Models\WorldSession;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GameMetrics extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '10s';

    protected ?string $heading = 'Right now';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $openInstanceIds = RoomInstance::query()->where('is_open', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $counts = app(InstanceMembers::class)->counts(array_values($openInstanceIds));
        $inRooms = array_sum($counts);
        $perInstance = $counts === [] ? 0 : $inRooms / count($counts);

        return [
            Stat::make('Concurrent players', number_format(WorldSession::query()->count()))
                ->description('Open world sessions')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Players per open instance', number_format($perInstance, 1))
                ->description(count($counts).' open '.str('instance')->plural(count($counts)).', '.$inRooms.' in rooms, busiest '.($counts === [] ? 0 : max($counts)))
                ->icon(Heroicon::OutlinedMap),
            Stat::make('Chat messages', number_format(ChatMessage::query()->where('created_at', '>=', now()->subMinute())->count()))
                ->description('In the last minute')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight),
            Stat::make('Reports', number_format(ChatReport::query()->where('created_at', '>=', now()->subHour())->count()))
                ->description('In the last hour')
                ->icon(Heroicon::OutlinedFlag),
        ];
    }
}
