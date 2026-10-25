<?php

namespace App\Http\Controllers\World;

use App\Actions\Presence\StartWorldSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StartWorldSessionController extends Controller
{
    public function __invoke(Request $request, StartWorldSession $startWorldSession): JsonResponse
    {
        $session = $startWorldSession($request->user());

        return WorldResponse::json([
            'session_token' => $session->session_token,
            'user_id' => $session->user_id,
        ], 201);
    }
}
