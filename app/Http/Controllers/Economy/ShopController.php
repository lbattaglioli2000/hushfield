<?php

namespace App\Http\Controllers\Economy;

use App\Domain\World\FixtureKind;
use App\Domain\World\FixtureReach;
use App\Features\ShopEnabled;
use App\Features\WorldFlags;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop's shelves. Members-only items are listed (locked) so residents know they exist.
 * While the shop kill switch is off the shelves stay visible but `open` is false,
 * so the client can say why nothing can be bought. Only players in a room with
 * a shop counter can see the shelves at all.
 */
final class ShopController extends Controller
{
    public function __invoke(Request $request, FixtureReach $fixtures): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $fixtures->ensureWithin($user, FixtureKind::Shop);

        return WorldResponse::json([
            'open' => WorldFlags::active(ShopEnabled::class),
            'items' => Item::query()->inShop()->orderBy('price')->orderBy('name')->get()
                ->map(fn (Item $item): array => $item->toPayload())
                ->all(),
        ]);
    }
}
