<?php

namespace App\Http\Controllers\World;

use App\Actions\Voice\IssueVoiceToken;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VoiceTokenController extends Controller
{
    public function __invoke(Request $request, RoomInstance $instance, IssueVoiceToken $issueVoiceToken): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return WorldResponse::json($issueVoiceToken($user, $instance));
    }
}
