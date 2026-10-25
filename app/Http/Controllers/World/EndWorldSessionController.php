<?php

namespace App\Http\Controllers\World;

use App\Actions\Presence\EndWorldSession;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EndWorldSessionController extends Controller
{
    public function __invoke(Request $request, EndWorldSession $endWorldSession): Response
    {
        /** @var User $user */
        $user = $request->user();
        $endWorldSession($user, $user->worldSession?->session_token);

        return response()->noContent();
    }
}
