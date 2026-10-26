<?php

use App\Domain\World\Protocol;

return [

    /*
     * All world logic ("Hushfield time") runs in this timezone. Timestamps are
     * stored in UTC and converted only for world logic and display.
     */
    'timezone' => env('HUSHFIELD_TIMEZONE', 'America/New_York'),

    // Read-only mirror of Protocol::VERSION (bump it there) for code that reads config.
    'protocol' => Protocol::VERSION,

    'accounts' => [
        'minimum_age' => 13,
        'adult_age' => 18,
        'underage_cookie' => 'hf_age_gate',
        'underage_cookie_minutes' => 60 * 24,
        'username' => [
            'min' => 3,
            'max' => 16,
            // Blocked when the whole name or any underscore-separated segment matches.
            'reserved' => [
                'admin', 'administrator', 'mod', 'moderator', 'staff', 'official', 'support',
                'system', 'hushfield', 'hearthmore', 'augustin', 'augustinmoss', 'moss', 'whsh',
                'radio', 'mayor', 'concierge', 'landlord', 'residences', 'route0', 'routezero',
                'sparehour', 'nobody', 'everyone',
            ],
            // Blocked anywhere inside the (normalized) name.
            'reserved_fragments' => ['admin', 'moderator', 'hearthmore', 'augustin', 'whsh', 'hushfield'],
        ],
    ],

    'world' => [
        'default_room' => 'town-square',
        'capacity_per_instance' => 40,
        'close_empty_instances_after_minutes' => 10,
    ],

    'movement' => [
        'speed_tiles_per_sec' => 4,
        'max_path_steps' => 200,
    ],

    'presence' => [
        'heartbeat_seconds' => 20,
        'ghost_after_seconds' => 60,
        'afk_after_seconds' => 300,
    ],

    'chat' => [
        'max_length' => 120,
        'repeat_window_seconds' => 30,
        'retention_days' => 30,
        'bubble_seconds' => 6,
    ],

    'friends' => [
        'max_friends' => 200,
        // Requests a resident may have waiting for an answer at once.
        'max_pending_requests' => 50,
        // Only residents in the same age bracket (13–17, 18+) can become friends.
        'same_age_bracket_only' => true,
    ],

    'housing' => [
        // Pieces one home may have out at once.
        'max_furniture' => 50,
    ],

    'economy' => [
        // Coins Hearthmore Group pays each resident once per Hushfield day.
        'daily_stipend' => 25,
    ],

    /*
     * Fishing off the end of The Pier. The server rolls the bite and the catch
     * when the line goes in, and judges the reel against its own clock.
     */
    'fishing' => [
        // How long the line sits before something bites, picked at random between these.
        'bite_after_ms' => [2000, 7000],
        // How long after the bite a reel still lands it. Covers reaction time and a round trip.
        'reel_window_ms' => 1500,
        // Coins fishing may pay one resident per Hushfield day; past it catches pay nothing.
        'daily_cap' => 100,
        /*
         * What can come up, keyed by the catch's name in lang/en/hushfield.php "catches".
         * Weight is relative odds; rarity is an ItemRarity value. Rare and peculiar
         * catches are announced to the room.
         */
        'catches' => [
            'herring' => ['weight' => 30, 'rarity' => 'common', 'coins' => 3],
            'mackerel' => ['weight' => 22, 'rarity' => 'common', 'coins' => 4],
            'boot' => ['weight' => 15, 'rarity' => 'common', 'coins' => 1],
            'eel' => ['weight' => 12, 'rarity' => 'uncommon', 'coins' => 8],
            'flounder' => ['weight' => 10, 'rarity' => 'uncommon', 'coins' => 7],
            'key' => ['weight' => 5, 'rarity' => 'rare', 'coins' => 20],
            'letter' => ['weight' => 3, 'rarity' => 'rare', 'coins' => 25],
            'not_a_fish' => ['weight' => 2, 'rarity' => 'peculiar', 'coins' => 40],
            'lost_hour' => ['weight' => 1, 'rarity' => 'peculiar', 'coins' => 30],
        ],
    ],

    'maps' => [
        'path' => resource_path('maps'),
    ],

    /*
     * Where browsers reach Reverb. Defaults to the server-side Reverb host, but
     * differs when a proxy (a local portal, Laravel Cloud's managed cluster) sits in front.
     */
    'realtime' => [
        'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', 'localhost')),
        'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
    ],

    'assets' => [
        // Point at a CDN later; the client only ever reads URLs from the manifest.
        'base_url' => env('HUSHFIELD_ASSET_URL'),
        'version' => env('HUSHFIELD_ASSET_VERSION', '2'),
        // Manifest key => file name under {base_url}/v{version}/.
        'images' => [
            'tiles' => 'tiles.png',
            'resident' => 'resident.png',
            'marker' => 'marker.png',
            'hover' => 'hover.png',
        ],
    ],

];
