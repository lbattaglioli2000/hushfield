<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One furniture facing uploaded in the admin catalog (docs/assets-plan.md §8):
 * the art checked, doubled and stored on the public disk under `path`, named
 * by its content hash. Never overwritten; publishing an older upload rolls
 * back to it. The game draws a facing's published upload, if it isn't
 * stale, in place of any art for it in resources/art.
 *
 * @property int $id
 * @property int $item_id
 * @property int $facing 0–3, as in resources/maps/README.md, "Furniture".
 * @property string $path Its key on the public disk: items/{slug}/{facing}.{hash}.png.
 * @property string $sha256 Of the stored file.
 * @property int $bytes The file as uploaded.
 * @property int $width In game pixels, as stored.
 * @property int $height In game pixels, as stored.
 * @property int|null $uploaded_by
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property Carbon|null $stale_at When the item's size or shape changed after this was drawn.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read User|null $uploader
 * @property-read User|null $publisher
 */
#[Fillable(['item_id', 'facing', 'path', 'sha256', 'bytes', 'width', 'height', 'uploaded_by', 'published_at', 'published_by', 'stale_at'])]
class ItemArtUpload extends Model
{
    protected $table = 'item_art';

    protected function casts(): array
    {
        return [
            'facing' => 'integer',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'published_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
