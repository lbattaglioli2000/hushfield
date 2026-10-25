<?php

namespace App\Http\Controllers\Housing;

use App\Actions\Housing\SetHomeVisitors;
use App\Domain\Housing\HomeVisitors;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class HomeVisitorsController extends Controller
{
    public function __invoke(Request $request, SetHomeVisitors $setHomeVisitors): JsonResponse
    {
        $request->validate(['visitors' => ['required', Rule::enum(HomeVisitors::class)]]);

        /** @var User $user */
        $user = $request->user();
        $home = $setHomeVisitors($user, $request->enum('visitors', HomeVisitors::class) ?? HomeVisitors::Closed);

        return WorldResponse::json([
            'message' => __(match ($home->visitors) {
                HomeVisitors::Everyone => 'hushfield.home_opened',
                HomeVisitors::Friends => 'hushfield.home_friends',
                HomeVisitors::Closed => 'hushfield.home_shut',
            }),
            'own' => ['instance_id' => $home->room_instance_id, 'visitors' => $home->visitors->value],
        ]);
    }
}
