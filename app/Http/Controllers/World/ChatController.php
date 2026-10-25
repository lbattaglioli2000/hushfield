<?php

namespace App\Http\Controllers\World;

use App\Actions\Chat\SendChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\ChatRequest;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;

final class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, RoomInstance $instance, SendChatMessage $sendChatMessage): JsonResponse
    {
        $message = $sendChatMessage($request->user(), $instance, $request->string('body')->toString());

        if ($message === null) {
            return response()->json(['dropped' => true], 202);
        }

        return response()->json([
            'protocol' => (int) config('hushfield.protocol'),
            'id' => $message->id,
            'user_id' => $message->user_id,
            'body' => $message->filtered_body,
        ], 201);
    }
}
