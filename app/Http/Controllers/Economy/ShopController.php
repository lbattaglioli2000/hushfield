<?php

namespace App\Http\Controllers\Economy;

use App\Features\ShopEnabled;
use App\Features\WorldFlags;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

/**
 * The shop's shelves. Members-only items are listed (locked) so residents know they exist.
 * While the shop kill switch is off the shelves stay visible but `open` is false,
 * so the client can say why nothing can be bought.
 */
final class ShopController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return WorldResponse::json([
            'open' => WorldFlags::active(ShopEnabled::class),
            'items' => Item::query()->inShop()->orderBy('price')->orderBy('name')->get()
                ->map(fn (Item $item): array => $item->toPayload())
                ->all(),
        ]);
    }
}
