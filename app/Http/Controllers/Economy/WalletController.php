<?php

namespace App\Http\Controllers\Economy;

use App\Http\Controllers\Controller;
use App\Http\Responses\WalletResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
    public function __invoke(Request $request, WalletResponse $response): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $response->for($user);
    }
}
