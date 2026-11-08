<?php

namespace App\Http\Controllers\Avatars;

use App\Actions\Avatars\ChangeOutfit;
use App\Http\Controllers\Controller;
use App\Http\Responses\WalletResponse;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wearing and taking off: answers with the wallet, whose `worn` lists what is
 * on; the room (the wearer too) redraws them from OutfitChanged.
 */
final class OutfitController extends Controller
{
    public function wear(Request $request, InventoryItem $piece, ChangeOutfit $changeOutfit, WalletResponse $wallet): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changeOutfit($user, $piece, true);

        return $wallet->for($user);
    }

    public function takeOff(Request $request, InventoryItem $piece, ChangeOutfit $changeOutfit, WalletResponse $wallet): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changeOutfit($user, $piece, false);

        return $wallet->for($user);
    }
}
