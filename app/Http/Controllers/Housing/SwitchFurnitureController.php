<?php

namespace App\Http\Controllers\Housing;

use App\Actions\Housing\SwitchFurniture;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SwitchFurnitureController extends Controller
{
    public function __invoke(Request $request, InventoryItem $piece, SwitchFurniture $switchFurniture): JsonResponse
    {
        $request->validate(['on' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();
        $instance = $switchFurniture($user, $piece, $request->boolean('on'));

        return WorldResponse::json(['furniture' => $instance->furniturePayload()]);
    }
}
