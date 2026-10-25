<?php

namespace App\Http\Controllers\Economy;

use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

/**
 * The shop's shelves. Members-only items are listed (locked) so residents know they exist.
 */
final class ShopController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return WorldResponse::json([
            'items' => Item::query()->inShop()->orderBy('price')->orderBy('name')->get()
                ->map(fn (Item $item): array => $item->toPayload())
                ->all(),
        ]);
    }
}
