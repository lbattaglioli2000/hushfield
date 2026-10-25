<?php

namespace App\Http\Controllers\World;

use App\Domain\Moderation\ReportReason;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the game shell. Everything after this is JSON and websockets, not Inertia.
 */
final class PlayController extends Controller
{
    /**
     * The lines from lang/en/hushfield.php the game client shows.
     * Must match the Copy type in resources/js/game/copy.ts.
     */
    public const COPY_KEYS = [
        'entering',
        'reconnecting',
        'reconnected',
        'please_reload',
        'please_reload_action',
        'session_moved',
        'session_moved_action',
        'banned',
        'banned_action',
        'warned',
        'not_in_room',
        'shop_title',
        'shop_intro',
        'shop_closed',
        'inventory_title',
        'inventory_empty',
        'stipend_offer',
        'members_only',
        'residences_title',
        'residences_intro',
        'residences_empty',
        'decorate_hint',
        'friends_title',
        'friends_empty',
        'town_map_title',
        'town_map_intro',
        'town_map_unplaced',
        'chat_placeholder',
        'on_air_host',
        'on_air',
    ];

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
                    'scheme' => config('hushfield.realtime.scheme') === 'https' ? 'https' : 'http',
                ],
                'heartbeatSeconds' => (int) config('hushfield.presence.heartbeat_seconds'),
                'afkSeconds' => (int) config('hushfield.presence.afk_after_seconds'),
                'chatMaxLength' => (int) config('hushfield.chat.max_length'),
                'bubbleSeconds' => (int) config('hushfield.chat.bubble_seconds'),
                'manifestUrl' => route('assets.manifest'),
                'reportReasons' => collect(ReportReason::cases())->map(fn (ReportReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
                'copy' => Arr::only((array) __('hushfield'), self::COPY_KEYS),
                'stations' => $this->stations(),
            ],
        ]);
    }

    /**
     * Every station on the tuning dial, low to high: each room with a frequency
     * on its map, plus the one homes share. Labels come from hushfield.stations.
     *
     * @return list<array{frequency: float, label: string}>
     */
    private function stations(): array
    {
        $stations = [];

        // Keyed by frequency, so each frequency appears once whichever room claims it.
        foreach (Room::query()->where('is_active', true)->get() as $room) {
            if ($room->frequency !== null) {
                $stations[(string) $room->frequency] = ['frequency' => $room->frequency, 'label' => $this->stationLabel($room->slug, $room->name)];
            }

            if ($room->home_frequency !== null) {
                $stations[(string) $room->home_frequency] = ['frequency' => $room->home_frequency, 'label' => $this->stationLabel('home', 'Home')];
            }
        }

        usort($stations, fn (array $a, array $b): int => $a['frequency'] <=> $b['frequency']);

        return $stations;
    }

    private function stationLabel(string $key, string $fallback): string
    {
        return Lang::has("hushfield.stations.{$key}") ? (string) __("hushfield.stations.{$key}") : $fallback;
    }
}
