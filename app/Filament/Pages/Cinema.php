<?php

namespace App\Filament\Pages;

use App\Actions\Cinema\CancelScreening;
use App\Actions\Cinema\ScheduleScreening;
use App\Domain\Cinema\FilmRefused;
use App\Domain\Cinema\YouTube;
use App\Features\WorldFlag;
use App\Filament\Support\Staff;
use App\Models\Screening;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The VFA's projection booth: what the screen shows now, what is queued after
 * it, and the controls to queue a film, play one now, or take one off.
 */
class Cinema extends Page
{
    protected string $view = 'filament.pages.cinema';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'World';

    protected static ?string $navigationLabel = 'VFA screen';

    protected static ?string $title = 'VFA screen';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Screening::class);
    }

    /**
     * @return array{showing: Screening|null, queued: Collection<int, Screening>, open: bool, now: CarbonInterface}
     */
    protected function getViewData(): array
    {
        $now = now();
        $programme = Screening::query()->notOver($now)->with('scheduler')->get();
        $showing = $programme->first(fn (Screening $screening): bool => $screening->isShowing($now));

        return [
            'showing' => $showing,
            'queued' => $programme->reject(fn (Screening $screening): bool => $screening->is($showing))->values(),
            'open' => WorldFlag::Cinema->isActive(),
            'now' => $now,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->scheduleAction('queue', playNow: false), $this->scheduleAction('playNow', playNow: true)];
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(fn (array $arguments): string => $this->screening($arguments)->isShowing(now()) ? 'Stop' : 'Remove')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->size('sm')
            ->authorize('delete', Screening::class)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Take "'.$this->screening($arguments)->title.'" off')
            ->modalDescription('A film showing now stops for everyone, and the queue moves up after an intermission. A queued film is removed and the ones after it move up.')
            ->action(function (array $arguments, CancelScreening $cancel, Action $action): void {
                if (! $cancel($this->screening($arguments), Staff::current())) {
                    Notification::make()->warning()->title('That film has already finished.')->send();
                    $action->cancel();
                }

                $action->successNotificationTitle('Taken off the programme.');
                $action->success();
            });
    }

    private function scheduleAction(string $name, bool $playNow): Action
    {
        return Action::make($name)
            ->label($playNow ? 'Play now' : 'Queue a film')
            ->icon($playNow ? Heroicon::OutlinedPlay : Heroicon::OutlinedQueueList)
            ->color($playNow ? 'danger' : 'primary')
            ->authorize('create', Screening::class)
            ->modalHeading($playNow ? 'Play a film now' : 'Queue a film')
            ->modalDescription($playNow
                ? 'Whatever is showing stops, this starts for everyone in the VFA at once, and the queue moves back behind it.'
                : 'It starts after the last film in the queue, with an intermission between, or at once if the screen is empty.')
            ->schema([
                TextInput::make('video')
                    ->label('YouTube link or video id')
                    ->placeholder('https://www.youtube.com/watch?v=…')
                    ->helperText('Prefer films without ads: an ad holds up one viewer, and they skip ahead to catch up when it ends.')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, YouTube $youTube, ScheduleScreening $schedule, Action $action) use ($playNow): void {
                try {
                    $film = $youTube->lookup((string) $data['video']);
                } catch (FilmRefused $refused) {
                    Notification::make()->danger()->title("The VFA can't show that")->body($refused->getMessage())->send();
                    // Keeps the form open, so the link can be corrected.
                    $action->halt();

                    return;
                }

                $screening = $schedule($film, $playNow, Staff::current());

                $action->successNotificationTitle($playNow
                    ? "Now showing: {$screening->title}"
                    : "Queued: {$screening->title}, at {$screening->starts_at->timezone(config('hushfield.timezone'))->format('g:i A')}");
                $action->success();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function screening(array $arguments): Screening
    {
        $id = filter_var($arguments['screening'] ?? null, FILTER_VALIDATE_INT);

        abort_if($id === false, 404);

        return Screening::query()->findOrFail($id);
    }
}
