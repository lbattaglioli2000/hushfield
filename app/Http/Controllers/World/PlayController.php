<?php

namespace App\Http\Controllers\World;

use App\Domain\Moderation\ReportReason;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the game shell. Everything after this is JSON and websockets, not Inertia.
 */
final class PlayController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Play', [
            'game' => [
                'protocol' => (int) config('hushfield.protocol'),
                'roomSlug' => (string) config('hushfield.world.default_room'),
                'maxPathSteps' => (int) config('hushfield.movement.max_path_steps'),
                'realtime' => [
                    'key' => (string) config('broadcasting.connections.reverb.key'),
                    'host' => (string) config('hushfield.realtime.host'),
                    'port' => (int) config('hushfield.realtime.port'),
                    'scheme' => (string) config('hushfield.realtime.scheme'),
                ],
                'heartbeatSeconds' => (int) config('hushfield.presence.heartbeat_seconds'),
                'afkSeconds' => (int) config('hushfield.presence.afk_after_seconds'),
                'chatMaxLength' => (int) config('hushfield.chat.max_length'),
                'bubbleSeconds' => (int) config('hushfield.chat.bubble_seconds'),
                'manifestUrl' => route('assets.manifest'),
                'reportReasons' => collect(ReportReason::cases())->map(fn (ReportReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
                'copy' => __('hushfield'),
            ],
        ]);
    }
}
