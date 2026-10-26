<?php

use App\Actions\Items\PublishItemArt;
use App\Actions\Items\UnpublishItemArt;
use App\Actions\Items\UploadItemArt;
use App\Domain\Assets\Image;
use App\Domain\Assets\InvalidAssetException;
use App\Domain\Assets\Png;
use App\Domain\Identity\StaffRole;
use App\Exceptions\ModerationActionRefused;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\RelationManagers\ArtRelationManager;
use App\Models\Item;
use App\Models\ItemArtUpload;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('public');
    // Uploads are served from the public disk until HUSHFIELD_ASSET_URL names the bucket.
    config(['hushfield.assets.base_url' => null]);
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
    $this->actingAs($this->admin);
    // A 2 × 1 love seat: 48 art pixels across, its footprint's bottom corner 32 in at facing 0, 16 in at facing 1.
    $this->seat = Item::factory()->furniture()->create(['slug' => 'love-seat', 'wide' => 2, 'deep' => 1]);
});

/**
 * A facing in art pixels: one colour standing on the footprint diamond whose
 * bottom corner touches the canvas's bottom `corner` pixels in, `tall` above it.
 */
function drawnFacing(int $width, int $corner, int $tall = 20, string $colour = "\x60\x40\x20\xFF"): Image
{
    $height = intdiv($width, 2) + $tall;
    $pixels = '';

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $pixels .= $y <= $height - abs($x + 0.5 - $corner) / 2 ? $colour : "\0\0\0\0";
        }
    }

    return new Image($width, $height, $pixels);
}

/** A PNG with a text chunk after its header, as art tools write metadata. */
function withMetadata(string $png): string
{
    $text = "Comment\0drawn in a hurry";
    $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32("tEXt{$text}"));

    return substr($png, 0, 33).$chunk.substr($png, 33);
}

/** The item's art as clients get it: URL by facing. */
function artPayload(Item $item): array
{
    return json_decode((string) json_encode($item->fresh()?->toPayload()), true)['furniture']['art'];
}

function uploadFacing(Item $item, int $facing, Image $art, User $staff): ItemArtUpload
{
    return app(UploadItemArt::class)($item, $facing, Png::encode($art), $staff);
}

test('an admin uploads a facing, which is checked, doubled, re-encoded, stored by its hash and drawn once published', function () {
    $art = drawnFacing(48, 32);

    Livewire::test(ArtRelationManager::class, ['ownerRecord' => $this->seat, 'pageClass' => EditItem::class])
        ->callAction(TestAction::make('upload')->table(), data: [
            'facing' => 0,
            'file' => UploadedFile::fake()->createWithContent('love-seat-0.png', withMetadata(Png::encode($art))),
            'publish' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Art uploaded');

    $upload = ItemArtUpload::query()->sole();
    $stored = (string) Storage::disk('public')->get($upload->path);

    expect($upload->path)->toBe('items/love-seat/0.'.substr(hash('sha256', $stored), 0, 8).'.png')
        ->and($upload->sha256)->toBe(hash('sha256', $stored))
        ->and([$upload->width, $upload->height])->toBe([96, 88])
        ->and($upload->published_by)->toBe($this->admin->id)
        ->and(Png::decode($stored)->equals($art->scale(2)))->toBeTrue()
        ->and($stored)->not->toContain('tEXt')
        ->and(artPayload($this->seat))->toBe([0 => Storage::disk('public')->url($upload->path)])
        ->and(Activity::query()->where('log_name', 'art')->pluck('event')->all())->toBe(['item_art.uploaded', 'item_art.published']);

    config(['hushfield.assets.base_url' => 'https://bucket.example.com/hf/']);

    expect(artPayload($this->seat))->toBe([0 => "https://bucket.example.com/hf/{$upload->path}"]);
});

test('art that breaks the canvas rule for the piece is refused, and nothing is stored', function (Closure $file, string $message) {
    expect(fn () => app(UploadItemArt::class)($this->seat, 0, $file(), $this->admin))
        ->toThrow(InvalidAssetException::class, $message);

    expect(ItemArtUpload::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'drawn for a 1 × 1 piece' => [fn () => Png::encode(drawnFacing(32, 16)), 'love-seat facing 0 is 32×36; it must be 48 wide.'],
    'standing on facing 1’s footprint' => [fn () => Png::encode(drawnFacing(48, 16)), 'love-seat facing 0 has pixels below its footprint diamond'],
    'exported at 200%' => [fn () => Png::encode(drawnFacing(48, 32)->scale(2)), 'love-seat facing 0 is already scaled up to 96×88. Export it at 100%: 48×44.'],
    'taller than any piece' => [fn () => Png::encode(drawnFacing(48, 32, 300)), 'love-seat facing 0 is 324 tall; a piece may be up to 256 tall.'],
    'not a PNG' => [fn () => 'GIF89a not really', 'love-seat facing 0 is not a PNG.'],
]);

test('each facing draws its published upload over repo art; publishing an older upload rolls back, unpublishing falls back', function () {
    // Repo art for facings 0 and 1, as hushfield:build-assets would index it.
    $built = sys_get_temp_dir().'/hushfield-item-art-'.uniqid();
    File::ensureDirectoryExists($built);
    File::put("{$built}/tiles.png", 'x');
    File::put("{$built}/assets.json", (string) json_encode([
        'version' => 'repo',
        'files' => ['tiles' => 'tiles.png', 'items/love-seat/0' => 'items/love-seat/0.aaaaaaaa.png', 'items/love-seat/1' => 'items/love-seat/1.bbbbbbbb.png'],
        'sizes' => ['tiles' => [64, 64], 'items/love-seat/0' => [96, 88], 'items/love-seat/1' => [96, 88]],
    ]));
    config(['hushfield.assets.output' => $built, 'hushfield.assets.index' => "{$built}/assets.json"]);
    $repo = fn (int $facing, string $hash) => asset("assets/items/love-seat/{$facing}.{$hash}.png");
    $url = fn (ItemArtUpload $upload) => Storage::disk('public')->url($upload->path);

    $first = uploadFacing($this->seat, 0, drawnFacing(48, 32, 20), $this->admin);
    $second = uploadFacing($this->seat, 0, drawnFacing(48, 32, 30, "\x20\x60\x40\xFF"), $this->admin);

    expect($first->path)->not->toBe($second->path)
        // Uploaded but not published: the repo's art still shows.
        ->and(artPayload($this->seat))->toBe([0 => $repo(0, 'aaaaaaaa'), 1 => $repo(1, 'bbbbbbbb')]);

    app(PublishItemArt::class)($first, $this->admin);
    app(PublishItemArt::class)($second, $this->admin);

    expect(artPayload($this->seat))->toBe([0 => $url($second), 1 => $repo(1, 'bbbbbbbb')])
        ->and($first->fresh()?->published_at)->toBeNull();

    app(PublishItemArt::class)($first->fresh(), $this->admin);

    expect(artPayload($this->seat))->toBe([0 => $url($first), 1 => $repo(1, 'bbbbbbbb')]);

    app(UnpublishItemArt::class)($first->fresh(), $this->admin);

    expect(artPayload($this->seat))->toBe([0 => $repo(0, 'aaaaaaaa'), 1 => $repo(1, 'bbbbbbbb')])
        ->and(ItemArtUpload::query()->whereNotNull('published_at')->count())->toBe(0);

    File::deleteDirectory($built);
});

test('only the named art publisher publishes', function () {
    $luigi = User::factory()->staff(StaffRole::Admin)->create();
    config(['hushfield.assets.publisher' => strtoupper($luigi->email)]);
    $upload = uploadFacing($this->seat, 0, drawnFacing(48, 32), $this->admin);

    Livewire::test(ArtRelationManager::class, ['ownerRecord' => $this->seat, 'pageClass' => EditItem::class])
        ->assertActionHidden(TestAction::make('publish')->table($upload));

    expect(fn () => app(PublishItemArt::class)($upload, $this->admin))->toThrow(ModerationActionRefused::class);

    app(PublishItemArt::class)($upload, $luigi);

    expect(artPayload($this->seat))->toHaveKey(0);
});

test('resizing or reshaping a piece makes its uploads stale: no longer drawn, and not publishable', function () {
    $upload = uploadFacing($this->seat, 0, drawnFacing(48, 32), $this->admin);
    app(PublishItemArt::class)($upload, $this->admin);
    $this->seat->update(['name' => 'Renamed seat']);

    expect($upload->fresh()?->stale_at)->toBeNull();

    $this->seat->update(['shape' => ['#.']]);

    expect($upload->fresh()?->stale_at)->not->toBeNull()
        ->and(artPayload($this->seat))->toBe([])
        ->and(fn () => app(PublishItemArt::class)($upload->fresh(), $this->admin))->toThrow(ModerationActionRefused::class);
});
