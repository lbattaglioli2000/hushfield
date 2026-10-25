<?php

namespace App\Http\Controllers\Housing;

use App\Actions\Housing\PutAwayFurniture;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PutAwayFurnitureController extends Controller
{
    public function __invoke(Request $request, Item $item, PutAwayFurniture $putAwayFurniture): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return WorldResponse::json(['furniture' => $putAwayFurniture($user, $item)?->furniturePayload() ?? []]);
    }
}
