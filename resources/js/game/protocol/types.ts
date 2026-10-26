/** Must match config('hushfield.protocol'). A server payload with a higher value means this build is outdated. */
export const PROTOCOL = 2;

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

export type RoomKind = 'public' | 'residence' | 'yard';

export type HomeVisitors = 'closed' | 'friends' | 'everyone';

/** The home a snapshot's instance belongs to. */
export type HomeInfo = {
    id: number;
    owner_id: number;
    owner: string;
    visitors: HomeVisitors;
};

/**
 * An owned furniture item standing in a home: its back corner on tile (x, y),
 * turned `rotation` quarter turns (see shared/furniture.ts).
 */
export type PlacedFurniture = {
    /** The owned piece placed: one of possibly several of its item (see OwnedItem). */
    piece: number;
    x: number;
    y: number;
    rotation: number;
    item: Item;
};

export type Snapshot = {
    protocol: number;
    server_time_ms: number;
    /** The asset manifest version the server is drawing with. */
    assets_version: string;
    instance: { id: number; number: number; label: string; channel: string };
    room: {
        slug: string;
        name: string;
        kind: RoomKind;
        /** Where the tuning dial points, in MHz; null leaves it off-station. */
        frequency: number | null;
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
    /** Art for buildings that have it, by building slug. The rest are drawn as placeholders. */
    buildings: Record<string, string>;
    /** Art for wall styles that have it, by style and part: `left`, `right`, `left-door`, `right-door`. */
    walls: Record<string, Record<string, string>>;
};

/** What a fixture on a map is for; App\Domain\World\FixtureKind on the server. */
export type FixtureKind = 'shop' | 'fishing';

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
    /** The item this is a colour of, or its own id: the shop shows one entry per family. */
    family: number;
    /** The colour's name, such as "Moss", and its swatch as #rrggbb; null for an item without colours. */
    colour: string | null;
    swatch: string | null;
    name: string;
    description: string | null;
    slot: ItemSlot;
    rarity: ItemRarity;
    price: number;
    members_only: boolean;
    /** How a furniture item stands in a home; null for everything else. */
    furniture: {
        /** Tiles along x and along y, before any turn. */
        wide: number;
        deep: number;
        /** How tall its placeholder block stands, in pixels. */
        height: number;
        /** Lies flat like a rug: walked over, and one other piece may stand on it. */
        floor: boolean;
        /** Which of those tiles it covers, as a Footprint mask (['##', '#.'] for an L); null for all of them. */
        shape: string[] | null;
        /** Art URL by facing, `0` to `3`, for the facings that have art (see facingArt). */
        art: Record<string, string>;
    } | null;
};

/** A resident's coins, stipend status and inventory, as every economy response carries them. */
export type Wallet = {
    balance: number;
    stipend: { amount: number; available: boolean; next_at: string };
    inventory: OwnedItem[];
};

/** An item the resident owns, newest first, with the id of every piece of it they own. */
export type OwnedItem = { item: Item; pieces: number[] };

/** A room on the town map. `position` is its grid cell, null for a map that sets none; `links` are the rooms its doors join it to. */
export type TownRoom = {
    slug: string;
    name: string;
    kind: 'public' | 'residence';
    frequency: number | null;
    position: { x: number; y: number } | null;
    links: string[];
    /** Everyone in the room's open instances; null for the residence, whose homes are private. */
    occupants: number | null;
};

export type TownMap = { rooms: TownRoom[] };

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

/** A line in the water: when it bites, and until when a reel lands it, in server time. The catch stays on the server. */
export type FishingCast = {
    id: string;
    bite_at_ms: number;
    reel_by_ms: number;
};

export type FishingCatch = {
    key: string;
    name: string;
    description: string;
    rarity: ItemRarity;
    coins: number;
};

/** How a reel went, as the server judged it. `paid` is 0 past the daily cap. */
export type ReelResult = {
    outcome: 'landed' | 'early' | 'late';
    catch: FishingCatch | null;
    paid: number;
};

/** Someone else in the room landed something rare; `message` is the line to show. */
export type RareCatchLandedEvent = Stamped & {
    user_id: number;
    message: string;
};

/** POST /world/instances/{id}/voice: where to connect, and whether this resident may speak or only listen. */
export type VoiceGrant = { url: string; token: string; can_speak: boolean };
