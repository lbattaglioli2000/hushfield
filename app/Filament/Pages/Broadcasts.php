<?php

namespace App\Filament\Pages;

use App\Actions\Radio\CancelBroadcast;
use App\Actions\Radio\ScheduleBroadcast;
use App\Domain\Radio\Airwaves;
use App\Domain\Radio\BroadcastRefused;
use App\Filament\Support\Staff;
use App\Models\Broadcast;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * WHSH's booth: what is on the air now and coming up, and the controls to
 * put an MP3 on the air now, schedule one for later, or take one off. Every
 * set in town plays it, and the beacon on the station flashes while it does.
 */
class Broadcasts extends Page
{
    protected string $view = 'filament.pages.broadcasts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRadio;

    protected static string|UnitEnum|null $navigationGroup = 'World';

    protected static ?string $navigationLabel = 'WHSH broadcasts';

    protected static ?string $title = 'WHSH broadcasts';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Broadcast::class);
    }

    /**
     * @return array{onAir: Broadcast|null, comingUp: list<Broadcast>, now: CarbonInterface}
     */
    protected function getViewData(): array
    {
        $now = now();
        $lineup = app(Airwaves::class)->lineup($now);
        $onAir = ($lineup[0] ?? null)?->starts_at->lessThanOrEqualTo($now) ? $lineup[0] : null;

        return [
            'onAir' => $onAir,
            'comingUp' => array_values(array_filter($lineup, fn (Broadcast $broadcast): bool => $broadcast !== $onAir)),
            'now' => $now,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->scheduleAction(false),
            $this->scheduleAction(true),
        ];
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(fn (array $arguments): string => $this->scheduled($arguments) ? 'Remove' : 'Take off the air')
            ->icon(fn (array $arguments): Heroicon => $this->scheduled($arguments) ? Heroicon::OutlinedXCircle : Heroicon::OutlinedStop)
            ->color('danger')
            ->size('sm')
            ->authorize('delete', Broadcast::class)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => $this->scheduled($arguments)
                ? 'Remove "'.$this->broadcast($arguments)->title.'" from the schedule'
                : 'Take "'.$this->broadcast($arguments)->title.'" off the air')
            ->modalDescription(fn (array $arguments): string => $this->scheduled($arguments)
                ? 'It will not go on the air. Nothing else on the schedule moves.'
                : 'It stops on every set at once, and the beacon on WHSH goes dark until the next broadcast.')
            ->action(function (array $arguments, CancelBroadcast $cancel, Action $action): void {
                $scheduled = $this->scheduled($arguments);

                if (! $cancel($this->broadcast($arguments), Staff::current())) {
                    Notification::make()->warning()->title('That broadcast has already finished.')->send();
                    $action->cancel();
                }

                $action->successNotificationTitle($scheduled ? 'Removed from the schedule.' : 'Taken off the air.');
                $action->success();
            });
    }

    private function scheduleAction(bool $later): Action
    {
        $maxBytes = (int) config('hushfield.radio.upload_max_bytes');
        $tz = (string) config('hushfield.timezone');
        $fields = [
            TextInput::make('title')
                ->label('Title')
                ->helperText('Shown on the ON AIR ticker while it plays.')
                ->required()
                ->maxLength(120),
            FileUpload::make('file')
                ->label('MP3')
                ->helperText('Up to '.intdiv($maxBytes, 1048576).' MB and '.config('hushfield.radio.max_minutes').' minutes.')
                ->acceptedFileTypes(['audio/mpeg', 'audio/mp3'])
                ->maxSize(intdiv($maxBytes, 1024))
                ->storeFiles(false)
                ->required(),
        ];

        if ($later) {
            $fields[] = DateTimePicker::make('starts_at')
                ->label('Goes on air at (Hushfield time)')
                ->timezone($tz)
                ->seconds(false)
                // The picker's state is in Hushfield time, so "now" must be too, not the app's UTC.
                ->after(fn (): string => now()->timezone($tz)->format('Y-m-d H:i:s'))
                ->required();
        }

        return Action::make($later ? 'schedule' : 'goOnAir')
            ->label($later ? 'Schedule a broadcast' : 'Go on air')
            ->icon($later ? Heroicon::OutlinedCalendar : Heroicon::OutlinedSignal)
            ->color($later ? 'warning' : 'danger')
            ->authorize('create', Broadcast::class)
            ->modalHeading($later ? 'Schedule a broadcast' : 'Put a recording on the air')
            ->modalDescription($later
                ? 'It goes on the air at exactly this time on every set in town and never moves. Whatever is on then is cut short. Scheduled broadcasts can\'t overlap.'
                : 'It starts at once on every set in town, through each player\'s Volume knob, and takes off the air whatever is on now.')
            ->schema($fields)
            ->action(function (array $data, ScheduleBroadcast $schedule, Action $action) use ($later, $tz): void {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                // Livewire may keep uploads on a bucket, where the path is a key and not a file here: copy it to one.
                $local = (string) tempnam(sys_get_temp_dir(), 'broadcast');
                file_put_contents($local, $file->readStream());

                try {
                    $startsAt = $later ? CarbonImmutable::parse((string) $data['starts_at'], 'UTC') : null;
                    $broadcast = $schedule(trim((string) $data['title']), $local, Staff::current(), $startsAt);
                } catch (BroadcastRefused $refused) {
                    Notification::make()->danger()->title("WHSH can't air that")->body($refused->getMessage())->send();
                    // Keeps the form open, so it can be corrected.
                    $action->halt();

                    return;
                } finally {
                    @unlink($local);
                }

                $action->successNotificationTitle($later
                    ? "Scheduled: {$broadcast->title}, at {$broadcast->starts_at->timezone($tz)->format('D j M, g:i A')}"
                    : "On the air: {$broadcast->title}");
                $action->success();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function scheduled(array $arguments): bool
    {
        return $this->broadcast($arguments)->starts_at->isFuture();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function broadcast(array $arguments): Broadcast
    {
        $id = filter_var($arguments['broadcast'] ?? null, FILTER_VALIDATE_INT);

        abort_if($id === false, 404);

        return Broadcast::query()->findOrFail($id);
    }
}
