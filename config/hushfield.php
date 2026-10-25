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
        'version' => env('HUSHFIELD_ASSET_VERSION', '1'),
        // Manifest key => file name under {base_url}/v{version}/.
        'images' => [
            'tiles' => 'tiles.png',
            'resident' => 'resident.png',
            'marker' => 'marker.png',
            'hover' => 'hover.png',
        ],
    ],

];
