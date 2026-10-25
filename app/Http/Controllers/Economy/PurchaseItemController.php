<?php

namespace App\Http\Controllers\Economy;

use App\Actions\Economy\PurchaseItem;
use App\Http\Controllers\Controller;
use App\Http\Responses\WalletResponse;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseItemController extends Controller
{
    public function __invoke(Request $request, Item $item, PurchaseItem $purchaseItem, WalletResponse $response): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $purchaseItem($user, $item);

        return $response->for($user, (string) __('hushfield.purchase_complete'));
    }
}
