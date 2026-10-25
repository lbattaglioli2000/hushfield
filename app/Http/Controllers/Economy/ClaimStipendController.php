<?php

namespace App\Http\Controllers\Economy;

use App\Actions\Economy\ClaimDailyStipend;
use App\Http\Controllers\Controller;
use App\Http\Responses\WalletResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClaimStipendController extends Controller
{
    public function __invoke(Request $request, ClaimDailyStipend $claimDailyStipend, WalletResponse $response): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $claimDailyStipend($user);

        return $response->for($user, (string) __('hushfield.stipend_granted'), 201);
    }
}
