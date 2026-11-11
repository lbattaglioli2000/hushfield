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
 * its content hash. It may come with an animation (`strip`, with its frame
 * times) that plays over the picture while a placed piece is switched on,
 * checked and stored the same way on the same row, so a picture and its
 * animation are published and rolled back together. The upload is a new
 * row, not yet published. Audited.
 */
final readonly class UploadItemArt
{
    public function __construct(private AssetBuilder $builder) {}

    /**
     * @param  list<int>  $frameMs  The strip's frame times: one for every frame, or one for each.
     */
    public function __invoke(Item $item, int $facing, string $bytes, User $staff, ?string $strip = null, array $frameMs = []): ItemArtUpload
    {
        if ($item->slot !== ItemSlot::Furniture) {
            throw ModerationActionRefused::notFurniture();
        }

        $name = "{$item->slug} facing {$facing}";
        $maxHeight = (int) config('hushfield.assets.upload_max_height');
        $size = $this->size($bytes, $name);

        if ($size[0] > 2 * 16 * 16 || $size[1] > 2 * $maxHeight) {
            throw new InvalidAssetException("{$name} is {$size[0]}×{$size[1]}, far bigger than any piece. Export it at 100%.");
        }

        $lies = $item->footprintAt(0, 0, $facing);
        $image = $this->builder->furnitureFacing($bytes, $name, $lies->wide, $lies->deep);

        if ($size[1] > $maxHeight) {
            throw new InvalidAssetException("{$name} is {$size[1]} tall; a piece may be up to {$maxHeight} tall.");
        }

        $animation = null;

        if ($strip !== null) {
            $stripName = "{$name}, switched on";

            // Drawn just over the piece, an animation on a rug would cover anyone standing on it.
            if ($item->floor) {
                throw new InvalidAssetException("{$stripName}: rugs and other floor pieces can't play an animation yet.");
            }

            $stripSize = $this->size($strip, $stripName);

            // Read from the header before anything is unpacked; the trimmed strip has its own limit.
            if ($stripSize[0] > 4096 || $stripSize[1] > 2 * $maxHeight) {
                throw new InvalidAssetException("{$stripName} is {$stripSize[0]}×{$stripSize[1]}; a strip may be up to 4096 wide. Export it at 100%, with fewer frames.");
            }

            $animation = $this->builder->furnitureAnimation($strip, $stripName, $image, $lies->wide, $lies->deep, $frameMs);
        }

        $png = Png::encode($image);
        $sha = hash('sha256', $png);
        $path = $this->store("items/{$item->slug}/{$facing}", $png);
        $animationPath = $animation === null ? null : $this->store("items/{$item->slug}/{$facing}-on", Png::encode($animation[0]));

        $upload = ItemArtUpload::query()->create([
            'item_id' => $item->id,
            'facing' => $facing,
            'path' => $path,
            'sha256' => $sha,
            'bytes' => strlen($bytes),
            'width' => $image->width,
            'height' => $image->height,
            'animation_path' => $animationPath,
            'animation' => $animation[1] ?? null,
            'uploaded_by' => $staff->id,
        ]);

        activity(AuditLog::Art)
            ->performedOn($item)
            ->causedBy($staff)
            ->withProperties(['item_art_id' => $upload->id, 'item' => $item->slug, 'facing' => $facing, 'path' => $path, 'animation_path' => $animationPath])
            ->event('item_art.uploaded')
            ->log("Uploaded {$item->name} art, facing {$facing}");

        return $upload;
    }

    /**
     * A PNG's width and height from its header, so nothing huge is unpacked,
     * after checking it isn't too many bytes.
     *
     * @return array{int, int}
     */
    private function size(string $bytes, string $name): array
    {
        $maxBytes = (int) config('hushfield.assets.upload_max_bytes');

        if (strlen($bytes) > $maxBytes) {
            throw new InvalidAssetException(sprintf('%s is %d KB; an upload may be up to %d KB.', $name, (int) ceil(strlen($bytes) / 1024), intdiv($maxBytes, 1024)));
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            throw new InvalidAssetException("{$name} is not a PNG.");
        }

        return [$size[0], $size[1]];
    }

    /** Stores a PNG on the public disk as {stem}.{hash}.png, and returns its key. */
    private function store(string $stem, string $png): string
    {
        $path = "{$stem}.".substr(hash('sha256', $png), 0, 8).'.png';
        $stored = Storage::disk((string) config('hushfield.assets.disk'))->put($path, $png, [
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'image/png',
        ]);

        if (! $stored) {
            throw new RuntimeException("Could not store {$path} on the public disk.");
        }

        return $path;
    }
}
