<?php

namespace App\Http\Controllers\World;

use App\Actions\Chat\SendChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\ChatRequest;
use App\Http\Responses\WorldResponse;
use App\Models\RoomInstance;
use Illuminate\Http\JsonResponse;

final class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, RoomInstance $instance, SendChatMessage $sendChatMessage): JsonResponse
    {
        $message = $sendChatMessage($request->user(), $instance, $request->chatBody());

        if ($message === null) {
            return WorldResponse::json(['dropped' => true], 202);
        }

        return WorldResponse::json($message->toPayload(), 201);
    }
}
