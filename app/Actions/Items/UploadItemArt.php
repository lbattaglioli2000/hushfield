<?php

namespace App\Actions\Items;

use App\Domain\Assets\AssetBuilder;
use App\Domain\Assets\InvalidAssetException;
use App\Domain\Assets\Png;
use App\Domain\Audit\AuditLog;
use App\Domain\Items\ItemSlot;
use App\Exceptions\ModerationActionRefused;
use App\Models\Item;
use App\Models\ItemArtUpload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Staff upload one facing of a furniture item's art (docs/assets-plan.md §8).
 * It gets the build's canvas checks, against the item's own size as it lies
 * at that facing, is doubled and re-encoded (which drops metadata and
 * anything else hidden in the file), and is stored on the public disk under
 * its content hash. The upload is a new row, not yet published. Audited.
 */
final readonly class UploadItemArt
{
    public function __construct(private AssetBuilder $builder) {}

    public function __invoke(Item $item, int $facing, string $bytes, User $staff): ItemArtUpload
    {
        if ($item->slot !== ItemSlot::Furniture) {
            throw ModerationActionRefused::notFurniture();
        }

        $name = "{$item->slug} facing {$facing}";
        $maxBytes = (int) config('hushfield.assets.upload_max_bytes');
        $maxHeight = (int) config('hushfield.assets.upload_max_height');

        if (strlen($bytes) > $maxBytes) {
            throw new InvalidAssetException(sprintf('%s is %d KB; an upload may be up to %d KB.', $name, (int) ceil(strlen($bytes) / 1024), intdiv($maxBytes, 1024)));
        }

        // Its size from the header, so nothing huge is unpacked: twice the largest allowed, for the exported-at-200% check.
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            throw new InvalidAssetException("{$name} is not a PNG.");
        }

        if ($size[0] > 2 * 16 * 16 || $size[1] > 2 * $maxHeight) {
            throw new InvalidAssetException("{$name} is {$size[0]}×{$size[1]}, far bigger than any piece. Export it at 100%.");
        }

        $lies = $item->footprintAt(0, 0, $facing);
        $image = $this->builder->furnitureFacing($bytes, $name, $lies->wide, $lies->deep);

        if ($size[1] > $maxHeight) {
            throw new InvalidAssetException("{$name} is {$size[1]} tall; a piece may be up to {$maxHeight} tall.");
        }

        $png = Png::encode($image);
        $sha = hash('sha256', $png);
        $path = "items/{$item->slug}/{$facing}.".substr($sha, 0, 8).'.png';
        $stored = Storage::disk((string) config('hushfield.assets.disk'))->put($path, $png, [
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'image/png',
        ]);

        if (! $stored) {
            throw new RuntimeException("Could not store {$path} on the public disk.");
        }

        $upload = ItemArtUpload::query()->create([
            'item_id' => $item->id,
            'facing' => $facing,
            'path' => $path,
            'sha256' => $sha,
            'bytes' => strlen($bytes),
            'width' => $image->width,
            'height' => $image->height,
            'uploaded_by' => $staff->id,
        ]);

        activity(AuditLog::Art)
            ->performedOn($item)
            ->causedBy($staff)
            ->withProperties(['item_art_id' => $upload->id, 'item' => $item->slug, 'facing' => $facing, 'path' => $path])
            ->event('item_art.uploaded')
            ->log("Uploaded {$item->name} art, facing {$facing}");

        return $upload;
    }
}
