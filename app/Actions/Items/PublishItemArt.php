<?php

namespace App\Actions\Items;

use App\Domain\Audit\AuditLog;
use App\Exceptions\ModerationActionRefused;
use App\Models\ItemArtUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The art publisher puts an uploaded facing in the game, in place of whatever
 * was drawn for that facing before: another upload (so publishing an older
 * one rolls back) or the art in resources/art. Players see it on their next
 * visit to a room it stands in. Audited.
 */
final readonly class PublishItemArt
{
    public function __invoke(ItemArtUpload $art, User $staff): void
    {
        if (! Gate::forUser($staff)->allows('publishArt', $art->item)) {
            throw ModerationActionRefused::notArtPublisher();
        }

        if ($art->stale_at !== null) {
            throw ModerationActionRefused::staleArt();
        }

        DB::transaction(function () use ($art, $staff): void {
            ItemArtUpload::query()
                ->where('item_id', $art->item_id)
                ->where('facing', $art->facing)
                ->whereKeyNot($art->id)
                ->update(['published_at' => null, 'published_by' => null]);

            $art->update(['published_at' => now(), 'published_by' => $staff->id]);
        });

        activity(AuditLog::Art)
            ->performedOn($art->item)
            ->causedBy($staff)
            ->withProperties(['item_art_id' => $art->id, 'item' => $art->item->slug, 'facing' => $art->facing, 'path' => $art->path])
            ->event('item_art.published')
            ->log("Published {$art->item->name} art, facing {$art->facing}");
    }
}
