<?php

namespace App\Filament\Pages;

use App\Actions\World\CloseInstance;
use App\Actions\World\GatherIntoFirstInstance;
use App\Domain\Presence\InstanceMembers;
use App\Filament\Support\Staff;
use App\Models\Room;
use App\Models\RoomInstance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Every room with its instances and live occupancy from Redis.
 */
class LiveRooms extends Page
{
    protected string $view = 'filament.pages.live-rooms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'World';

    protected static ?string $navigationLabel = 'Live rooms';

    protected static ?string $title = 'Live rooms';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Room::class);
    }

    /**
     * @return array{rooms: Collection<int, Room>, counts: array<int, int>, totals: array<int, int>}
     */
    protected function getViewData(): array
    {
        $rooms = Room::query()
            ->with(['instances' => fn ($query) => $query->orderBy('number')])
            ->orderBy('name')
            ->get();

        $counts = app(InstanceMembers::class)->countsFor($rooms->flatMap->instances);

        return [
            'rooms' => $rooms,
            'counts' => $counts,
            'totals' => $rooms->mapWithKeys(fn (Room $room): array => [
                $room->id => $room->instances->sum(fn (RoomInstance $instance): int => $counts[$instance->id] ?? 0),
            ])->all(),
        ];
    }

    public function closeInstanceAction(): Action
    {
        return Action::make('closeInstance')
            ->label('Close')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->size('sm')
            ->authorize('manageInstances', Room::class)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Close '.$this->instance($arguments)->label())
            ->modalDescription('Everyone in this instance is moved to the busiest open instance of the room.')
            ->action(function (array $arguments, CloseInstance $closeInstance, Action $action): void {
                $instance = $this->instance($arguments);

                // Another staff member, or the empty-instance sweep, may have closed it since the page rendered.
                if (! $instance->is_open) {
                    Notification::make()->warning()->title($instance->label().' is already closed.')->send();
                    $action->cancel();
                }

                $moved = $closeInstance($instance, Staff::current());

                $action->successNotificationTitle("Instance closed. Moved {$moved} ".str('player')->plural($moved).'.');
                $action->success();
            });
    }

    public function gatherAction(): Action
    {
        return Action::make('gather')
            ->label('Move everyone to instance 1')
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->color('warning')
            ->size('sm')
            ->authorize('manageInstances', Room::class)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Gather '.$this->room($arguments)->name.' into instance 1')
            ->modalDescription('Every other open instance of this room is closed and its players are moved to instance 1.')
            ->action(function (array $arguments, GatherIntoFirstInstance $gather, Action $action): void {
                $moved = $gather($this->room($arguments), Staff::current());

                $action->successNotificationTitle("Moved {$moved} ".str('player')->plural($moved).' to instance 1.');
                $action->success();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function instance(array $arguments): RoomInstance
    {
        return RoomInstance::query()->with('room')->findOrFail(self::argumentId($arguments, 'instance'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function room(array $arguments): Room
    {
        return Room::query()->findOrFail(self::argumentId($arguments, 'room'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function argumentId(array $arguments, string $key): int
    {
        $id = filter_var($arguments[$key] ?? null, FILTER_VALIDATE_INT);

        abort_if($id === false, 404);

        return $id;
    }
}
