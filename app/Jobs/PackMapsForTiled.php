<?php

namespace App\Jobs;

use App\Actions\World\PackMaps;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Packs the maps for Tiled on the queue, off the app's web instances, and
 * tells the staff member who asked, in the admin's notifications, with the
 * download link.
 */
final class PackMapsForTiled implements ShouldQueue
{
    use Queueable;

    /** Packing again is cheap: a staff member can press the button again. */
    public int $tries = 1;

    /** Its own queue: on Laravel Cloud, the "map-exports" managed queue, with more memory than the app's instances. */
    public const string QUEUE = 'map-exports';

    public function __construct(public User $staff)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PackMaps $packMaps): void
    {
        $pack = $packMaps();

        Notification::make()
            ->success()
            ->title('Maps packed for Tiled')
            ->body($pack['url'] !== null
                ? "{$pack['files']} files. Send the link to the designer: it works for ".PackMaps::LINK_HOURS.' hours.'
                : "{$pack['files']} files, in {$pack['path']} on the private disk. It's a local folder here, so there's no link.")
            ->actions($pack['url'] !== null ? [
                Action::make('download')->label('Download zip')->url($pack['url'])->openUrlInNewTab(),
            ] : [])
            ->persistent()
            ->sendToDatabase($this->staff, isEventDispatched: true);
    }

    public function failed(?Throwable $e): void
    {
        Notification::make()
            ->danger()
            ->title("The maps couldn't be packed")
            ->body($e?->getMessage())
            ->sendToDatabase($this->staff, isEventDispatched: true);
    }
}
