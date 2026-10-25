<?php

namespace App\Http\Controllers\World;

use App\Actions\Moderation\ReportMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\World\ReportRequest;
use App\Http\Responses\WorldResponse;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __invoke(ReportRequest $request, ReportMessage $reportMessage): JsonResponse
    {
        $reportMessage(
            $request->user(),
            $request->reportedUser(),
            $request->reason(),
            $request->chatMessage(),
            $request->roomInstance(),
        );

        return WorldResponse::json(['message' => __('hushfield.report_sent')], 201);
    }
}
