<?php

namespace App\Http\Controllers\Housing;

use App\Actions\Housing\PlaceFurniture;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlaceFurnitureController extends Controller
{
    public function __invoke(Request $request, Item $item, PlaceFurniture $placeFurniture): JsonResponse
    {
        $request->validate([
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $home = $placeFurniture($user, $item, $request->integer('x'), $request->integer('y'));

        return WorldResponse::json(['furniture' => $home->furniturePayload()]);
    }
}
