<?php

namespace App\Http\Controllers\World;

use App\Actions\Presence\StartWorldSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StartWorldSessionController extends Controller
{
    public function __invoke(Request $request, StartWorldSession $startWorldSession): JsonResponse
    {
        $session = $startWorldSession($request->user());

        return response()->json([
            'protocol' => (int) config('hushfield.protocol'),
            'session_token' => $session->session_token,
            'user_id' => $session->user_id,
        ], 201);
    }
}
