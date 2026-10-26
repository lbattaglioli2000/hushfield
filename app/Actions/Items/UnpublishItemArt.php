<?php

namespace App\Actions\Items;

use App\Domain\Audit\AuditLog;
use App\Exceptions\ModerationActionRefused;
use App\Models\ItemArtUpload;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The art publisher takes an uploaded facing out of the game. The facing goes
 * back to its art in resources/art, if it has any, or to the placeholder.
 * The upload stays, to publish again. Audited.
 */
final readonly class UnpublishItemArt
{
    public function __invoke(ItemArtUpload $art, User $staff): void
    {
        if (! Gate::forUser($staff)->allows('publishArt', $art->item)) {
            throw ModerationActionRefused::notArtPublisher();
        }

        $art->update(['published_at' => null, 'published_by' => null]);

        activity(AuditLog::Art)
            ->performedOn($art->item)
            ->causedBy($staff)
            ->withProperties(['item_art_id' => $art->id, 'item' => $art->item->slug, 'facing' => $art->facing, 'path' => $art->path])
            ->event('item_art.unpublished')
            ->log("Unpublished {$art->item->name} art, facing {$art->facing}");
    }
}
