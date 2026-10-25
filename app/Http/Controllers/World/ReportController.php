<?php

namespace App\Http\Controllers\World;

use App\Actions\Moderation\ReportMessage;
use App\Domain\Moderation\ReportReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\ReportRequest;
use App\Models\ChatMessage;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __invoke(ReportRequest $request, ReportMessage $reportMessage): JsonResponse
    {
        $reportMessage(
            $request->user(),
            User::query()->findOrFail($request->integer('reported_user_id')),
            $request->enum('reason', ReportReason::class) ?? ReportReason::Other,
            $request->filled('chat_message_id') ? ChatMessage::query()->find($request->integer('chat_message_id')) : null,
            $request->filled('room_instance_id') ? RoomInstance::query()->find($request->integer('room_instance_id')) : null,
        );

        return response()->json(['message' => __('hushfield.report_sent')], 201);
    }
}
