<?php

namespace App\Http\Controllers\Housing;

use App\Actions\Housing\SetHomeVisitors;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HomeVisitorsController extends Controller
{
    public function __invoke(Request $request, SetHomeVisitors $setHomeVisitors): JsonResponse
    {
        $request->validate(['open' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();
        $home = $setHomeVisitors($user, $request->boolean('open'));

        return WorldResponse::json([
            'message' => __($home->is_open ? 'hushfield.home_opened' : 'hushfield.home_shut'),
            'own' => ['instance_id' => $home->room_instance_id, 'is_open' => $home->is_open],
        ]);
    }
}
