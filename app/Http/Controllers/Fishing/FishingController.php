<?php

namespace App\Http\Controllers\Fishing;

use App\Actions\Fishing\CastLine;
use App\Actions\Fishing\ReelIn;
use App\Http\Controllers\Controller;
use App\Http\Responses\WalletResponse;
use App\Http\Responses\WorldResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FishingController extends Controller
{
    public function cast(Request $request, CastLine $castLine): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return WorldResponse::json(['cast' => $castLine($user)->toPayload()], 201);
    }

    /**
     * The reel's outcome, alongside the wallet it may have changed.
     */
    public function reel(Request $request, ReelIn $reelIn, WalletResponse $response): JsonResponse
    {
        /** @var array{cast_id: string} $data */
        $data = $request->validate(['cast_id' => ['required', 'string', 'uuid']]);

        /** @var User $user */
        $user = $request->user();
        $result = $reelIn($user, $data['cast_id']);

        return $response->for($user, $result->message(), extra: ['reel' => $result->toPayload()]);
    }
}
