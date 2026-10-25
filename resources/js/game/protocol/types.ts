/** Must match config('hushfield.protocol'). A server payload with a higher value means this build is outdated. */
export const PROTOCOL = 1;

export type Tile = [number, number];

export type AvatarLook = { body_color: string; sprite_key: string } | null;

export type PathState = {
    path: Tile[];
    started_at_ms: number;
    speed: number;
};

/** Just the path fields, without whatever else the payload carried. */
export function pathState({
    path,
    started_at_ms,
    speed,
}: PathState): PathState {
    return { path, started_at_ms, speed };
}

export type PlayerState = PathState & {
    id: number;
    username: string;
    avatar: AvatarLook;
};

export type RoomKind = 'public' | 'residence';

export type HomeVisitors = 'closed' | 'friends' | 'everyone';

/** The home a snapshot's instance belongs to. */
export type HomeInfo = {
    id: number;
    owner_id: number;
    owner: string;
    visitors: HomeVisitors;
};

/** An owned furniture item standing on a tile of a home. */
export type PlacedFurniture = { x: number; y: number; item: Item };

export type Snapshot = {
    protocol: number;
    server_time_ms: number;
    instance: { id: number; number: number; label: string; channel: string };
    room: {
        slug: string;
        name: string;
        kind: RoomKind;
        map_version: string;
        map_url: string;
    };
    /** Set when the instance is someone's residence. */
    home: HomeInfo | null;
    furniture: PlacedFurniture[];
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
export type IdleWhisper = { user_id: number; idle: boolean };

export type FurnitureChangedEvent = Stamped & {
    furniture: PlacedFurniture[];
};

export type InstanceReassignedEvent = Stamped & {
    message: string;
    target_instance_id: number | null;
    /** Rejoin this room instead of the current one. */
    room_slug: string | null;
};

export type AssetManifest = {
    version: string;
    images: Record<'tiles' | 'resident' | 'marker' | 'hover', string>;
};

export type ItemSlot =
    | 'head'
    | 'face'
    | 'body'
    | 'feet'
    | 'hand'
    | 'pin'
    | 'background'
    | 'furniture';

export type ItemRarity = 'common' | 'uncommon' | 'rare' | 'peculiar';

/** A catalog item, as the shop and the inventory show it. */
export type Item = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    slot: ItemSlot;
    rarity: ItemRarity;
    price: number;
    sprite_layers: string[];
    members_only: boolean;
};

/** A resident's coins, stipend status and inventory, as every economy response carries them. */
export type Wallet = {
    balance: number;
    stipend: { amount: number; available: boolean; next_at: string };
    inventory: Item[];
};

/** The resident's own home, and the homes open to visitors. `residence` is null until the residence map is imported. */
export type HomesList = {
    residence: string | null;
    own: { instance_id: number; visitors: HomeVisitors } | null;
    open: Array<{
        instance_id: number;
        owner: string;
        friends_only: boolean;
        visitors: number;
    }>;
};

export type PlayerRef = { id: number; username: string };

export type Friend = PlayerRef & {
    online: boolean;
    /** Where an online friend is; null when offline or between rooms. */
    location: {
        room: string;
        instance_id: number;
        label: string;
        can_join: boolean;
    } | null;
};

export type FriendsList = {
    friends: Friend[];
    incoming: Array<{ id: number; user: PlayerRef }>;
    outgoing: Array<{ id: number; user: PlayerRef }>;
    requests_allowed: boolean;
};

/** The answer to anything that changes a friends list. */
export type FriendsUpdate = { message: string | null; friends: FriendsList };

export type FriendsUpdatedEvent = Stamped & { message: string | null };
