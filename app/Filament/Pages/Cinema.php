<?php

namespace App\Filament\Pages;

use App\Actions\Cinema\CancelScreening;
use App\Actions\Cinema\ScheduleScreening;
use App\Actions\Cinema\SetHouseLights;
use App\Domain\Cinema\FilmRefused;
use App\Domain\Cinema\HouseLights;
use App\Domain\Cinema\Programme;
use App\Domain\Cinema\YouTube;
use App\Features\WorldFlag;
use App\Filament\Support\Staff;
use App\Models\Screening;
use App\Models\Theater;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The VFA's projection booth: for each theater, what its screen shows now,
 * what follows, its house lights, and the controls to queue a film, play
 * one now, schedule a premiere, or take one off.
 */
class Cinema extends Page
{
    protected string $view = 'filament.pages.cinema';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'World';

    protected static ?string $navigationLabel = 'VFA screens';

    protected static ?string $title = 'VFA screens';

    /** The theater being programmed, by room slug. */
    #[Url]
    public string $theater = '';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Screening::class);
    }

    public function mount(): void
    {
        $this->theater = $this->theater();
    }

    /**
     * @return array{theaters: array<string, string>, showing: Screening|null, queued: Collection<int, Screening>, lights: HouseLights, open: bool, now: CarbonInterface}
     */
    protected function getViewData(): array
    {
        $now = now();
        $programme = Screening::query()->inTheater($this->theater())->notOver($now)->with('scheduler')->get();
        $showing = $programme->first(fn (Screening $screening): bool => $screening->isShowing($now));

        return [
            'theaters' => app(Programme::class)->theaters(),
            'showing' => $showing,
            'queued' => $programme->reject(fn (Screening $screening): bool => $screening->is($showing))->values(),
            'lights' => Theater::lights($this->theater()),
            'open' => WorldFlag::Cinema->isActive(),
            'now' => $now,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->scheduleAction('queue'),
            $this->scheduleAction('premiere'),
            $this->scheduleAction('playNow'),
            $this->lightsAction(),
        ];
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
            ->modalDescription('A film showing now stops for everyone, and the queue moves up after an intermission. A film still to come is removed and the queue after it moves up.')
            ->action(function (array $arguments, CancelScreening $cancel, Action $action): void {
                if (! $cancel($this->screening($arguments), Staff::current())) {
                    Notification::make()->warning()->title('That film has already finished.')->send();
                    $action->cancel();
                }

                $action->successNotificationTitle('Taken off the programme.');
                $action->success();
            });
    }

    private function lightsAction(): Action
    {
        return Action::make('lights')
            ->label('House lights')
            ->icon(Heroicon::OutlinedLightBulb)
            ->color('gray')
            ->authorize('create', Screening::class)
            ->modalHeading(fn (): string => $this->theaterName().' house lights')
            ->modalDescription('They change for everyone in the theater at once.')
            ->fillForm(fn (): array => ['lights' => Theater::lights($this->theater())->value])
            ->schema([
                Select::make('lights')->label('House lights')->options(HouseLights::class)->required()->native(false),
            ])
            ->action(function (array $data, SetHouseLights $setLights, Action $action): void {
                $lights = $data['lights'] instanceof HouseLights ? $data['lights'] : HouseLights::from((string) $data['lights']);
                $setLights($this->theater(), $lights, Staff::current());

                $action->successNotificationTitle($this->theaterName().' house lights: '.$lights->getLabel());
                $action->success();
            });
    }

    /**
     * @param  'queue'|'premiere'|'playNow'  $mode
     */
    private function scheduleAction(string $mode): Action
    {
        $tz = (string) config('hushfield.timezone');
        $fields = [
            TextInput::make('video')
                ->label('YouTube link or video id')
                ->placeholder('https://www.youtube.com/watch?v=…')
                ->helperText('Prefer films without ads: an ad holds up one viewer, and they skip ahead to catch up when it ends.')
                ->required()
                ->maxLength(255),
        ];

        if ($mode === 'premiere') {
            $fields[] = DateTimePicker::make('starts_at')
                ->label('Starts at (Hushfield time)')
                ->timezone($tz)
                ->seconds(false)
                ->after('now')
                ->required();
        }

        return Action::make($mode)
            ->label(match ($mode) {
                'queue' => 'Queue a film',
                'premiere' => 'Schedule a premiere',
                'playNow' => 'Play now',
            })
            ->icon(match ($mode) {
                'queue' => Heroicon::OutlinedQueueList,
                'premiere' => Heroicon::OutlinedStar,
                'playNow' => Heroicon::OutlinedPlay,
            })
            ->color(match ($mode) {
                'queue' => 'primary',
                'premiere' => 'warning',
                'playNow' => 'danger',
            })
            ->authorize('create', Screening::class)
            ->modalHeading(fn (): string => match ($mode) {
                'queue' => 'Queue a film',
                'premiere' => 'Schedule a premiere',
                'playNow' => 'Play a film now',
            }.' in '.$this->theaterName())
            ->modalDescription(match ($mode) {
                'queue' => 'It starts after the last film in the queue, with an intermission between, or at once if the screen is empty. Queued films flow around premieres.',
                'premiere' => 'It starts at exactly this time and never moves. A film still showing then is cut short, and the queue flows around it.',
                'playNow' => 'Whatever is showing stops, this starts for everyone in the theater at once, and the queue moves back behind it.',
            })
            ->schema($fields)
            ->action(function (array $data, YouTube $youTube, ScheduleScreening $schedule, Action $action) use ($mode, $tz): void {
                try {
                    $film = $youTube->lookup((string) $data['video']);
                    $premiereAt = $mode === 'premiere' ? CarbonImmutable::parse((string) $data['starts_at'], 'UTC') : null;
                    $screening = $schedule($film, $this->theater(), $mode === 'playNow', Staff::current(), $premiereAt);
                } catch (FilmRefused $refused) {
                    Notification::make()->danger()->title("The VFA can't show that")->body($refused->getMessage())->send();
                    // Keeps the form open, so it can be corrected.
                    $action->halt();

                    return;
                }

                $at = $screening->starts_at->timezone($tz)->format('g:i A');
                $action->successNotificationTitle(match ($mode) {
                    'queue' => "Queued: {$screening->title}, at {$at}",
                    'premiere' => "Premiere: {$screening->title}, at {$screening->starts_at->timezone($tz)->format('D j M, g:i A')}",
                    'playNow' => "Now showing: {$screening->title}",
                });
                $action->success();
            });
    }

    /** The theater being programmed: the one asked for, if it is one, or the first. */
    private function theater(): string
    {
        $theaters = app(Programme::class)->theaters();

        return array_key_exists($this->theater, $theaters) ? $this->theater : (string) array_key_first($theaters);
    }

    private function theaterName(): string
    {
        return app(Programme::class)->theaters()[$this->theater()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function screening(array $arguments): Screening
    {
        $id = filter_var($arguments['screening'] ?? null, FILTER_VALIDATE_INT);

        abort_if($id === false, 404);

        return Screening::query()->inTheater($this->theater())->findOrFail($id);
    }
}
