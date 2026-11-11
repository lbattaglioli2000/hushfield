<?php

namespace App\Actions\Items;

use App\Domain\Audit\AuditLog;
use App\Models\Item;
use App\Models\ItemArtUpload;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Staff give other colours of a piece the animation one facing's upload
 * plays while it's on, so a strip drawn once (clothes tumbling, which look
 * the same in every washer) needn't be uploaded with every colour. Each
 * colour gets a new upload for that facing: its own published picture with
 * the same strip and frame times, which then publishes and rolls back like
 * any upload. A colour is skipped, with the reason, if it has no published
 * upload for that facing or its picture isn't the same size (colours share
 * a canvas, so the strip lines up only then). Audited.
 */
final readonly class CopyItemAnimation
{
    public function __construct(private PublishItemArt $publish) {}

    /**
     * The piece's other colours, the original included: the ones an animation may be copied to.
     *
     * @return Collection<int, Item>
     */
    public static function colours(Item $item): Collection
    {
        $original = $item->parent ?? $item;

        return collect([$original, ...$original->variants()->orderBy('id')->get()])
            ->reject(fn (Item $colour): bool => $colour->id === $item->id)
            ->values();
    }

    /**
     * @param  list<int>  $itemIds  Colours of the source's piece.
     * @return array{copied: list<string>, skipped: array<string, string>} by each colour's label
     */
    public function __invoke(ItemArtUpload $source, array $itemIds, User $staff, bool $publish = false): array
    {
        $result = ['copied' => [], 'skipped' => []];

        if ($source->animation_path === null || $source->animation === null) {
            return $result;
        }

        foreach (self::colours($source->item)->whereIn('id', $itemIds) as $colour) {
            /** @var ItemArtUpload|null $picture */
            $picture = $colour->publishedArt()->where('facing', $source->facing)->first();

            if ($picture === null) {
                $result['skipped'][$colour->label()] = "it has no published upload for facing {$source->facing}";

                continue;
            }

            if ([$picture->width, $picture->height] !== [$source->width, $source->height]) {
                $result['skipped'][$colour->label()] = 'its picture is a different size, so the animation would not line up';

                continue;
            }

            $copy = ItemArtUpload::query()->create([
                ...$picture->only(['item_id', 'facing', 'path', 'sha256', 'bytes', 'width', 'height']),
                'animation_path' => $source->animation_path,
                'animation' => $source->animation,
                'uploaded_by' => $staff->id,
            ]);

            activity(AuditLog::Art)
                ->performedOn($colour)
                ->causedBy($staff)
                ->withProperties(['item_art_id' => $copy->id, 'from_item_art_id' => $source->id, 'item' => $colour->slug, 'facing' => $source->facing, 'animation_path' => $source->animation_path])
                ->event('item_art.animation_copied')
                ->log("Copied the animation for facing {$source->facing} from {$source->item->label()} to {$colour->label()}");

            if ($publish) {
                ($this->publish)($copy, $staff);
            }

            $result['copied'][] = $colour->label();
        }

        return $result;
    }
}
