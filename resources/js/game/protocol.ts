/** Must match config('hushfield.protocol'). A server payload with a higher value means this build is outdated. */
export const PROTOCOL = 1;

export type Tile = [number, number];

export type AvatarLook = { body_color: string; sprite_key: string } | null;

export type PathState = {
    path: Tile[];
    started_at_ms: number;
    speed: number;
};

export type PlayerState = PathState & {
    id: number;
    username: string;
    avatar: AvatarLook;
};

export type Snapshot = {
    protocol: number;
    server_time_ms: number;
    instance: { id: number; number: number; label: string; channel: string };
    room: { slug: string; name: string; map_version: string; map_url: string };
    players: PlayerState[];
};

type Stamped = { protocol: number; at_ms: number };

export type PlayerJoinedEvent = Stamped & { player: PlayerState };
export type PlayerLeftEvent = Stamped & { user_id: number };
export type PlayerMovedEvent = Stamped & PathState & { user_id: number };
export type ChatMessageSentEvent = Stamped & {
    id: number;
    user_id: number;
    body: string;
};
export type InstanceReassignedEvent = Stamped & {
    target_instance_id: number | null;
};

export type AssetManifest = {
    version: string;
    images: Record<'tiles' | 'resident' | 'marker' | 'hover', string>;
};
