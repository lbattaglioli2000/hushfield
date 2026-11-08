<?php

namespace App\Filament\Pages;

use App\Actions\Radio\StartBroadcast;
use App\Actions\Radio\StopBroadcast;
use App\Domain\Radio\BroadcastRefused;
use App\Filament\Support\Staff;
use App\Models\Broadcast;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * WHSH's booth: what is on the air now, and the controls to put an MP3 on
 * the air or take it off. Every set in town plays it, and the beacon on the
 * station flashes while it does.
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
     * @return array{onAir: Broadcast|null, now: CarbonInterface}
     */
    protected function getViewData(): array
    {
        $now = now();

        return [
            'onAir' => Broadcast::query()->onAir($now)->with('scheduler')->first(),
            'now' => $now,
        ];
    }

    protected function getHeaderActions(): array
    {
        $maxBytes = (int) config('hushfield.radio.upload_max_bytes');

        return [
            Action::make('goOnAir')
                ->label('Go on air')
                ->icon(Heroicon::OutlinedSignal)
                ->color('danger')
                ->authorize('create', Broadcast::class)
                ->modalHeading('Put a recording on the air')
                ->modalDescription('It starts at once on every set in town, through each player\'s Volume knob, and takes off the air whatever is on now.')
                ->schema([
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
                ])
                ->action(function (array $data, StartBroadcast $start, Action $action): void {
                    $file = $data['file'];

                    if (! $file instanceof TemporaryUploadedFile) {
                        return;
                    }

                    try {
                        $broadcast = $start(trim((string) $data['title']), $file->getRealPath(), Staff::current());
                    } catch (BroadcastRefused $refused) {
                        Notification::make()->danger()->title("WHSH can't air that")->body($refused->getMessage())->send();
                        // Keeps the form open, so another file can be tried.
                        $action->halt();

                        return;
                    }

                    $action->successNotificationTitle("On the air: {$broadcast->title}");
                    $action->success();
                }),
        ];
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label('Take off the air')
            ->icon(Heroicon::OutlinedStop)
            ->color('danger')
            ->size('sm')
            ->authorize('delete', Broadcast::class)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Take "'.$this->broadcast($arguments)->title.'" off the air')
            ->modalDescription('It stops on every set at once, and the beacon on WHSH goes dark.')
            ->action(function (array $arguments, StopBroadcast $stop, Action $action): void {
                if (! $stop($this->broadcast($arguments), Staff::current())) {
                    Notification::make()->warning()->title('That broadcast has already finished.')->send();
                    $action->cancel();
                }

                $action->successNotificationTitle('Taken off the air.');
                $action->success();
            });
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
