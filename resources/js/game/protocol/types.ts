/** Must match config('hushfield.protocol'). A server payload with a higher value means this build is outdated. */
export const PROTOCOL = 6;

export type Tile = [number, number];

/** Something a resident wears: its slot, and its art by part (`front`, `back`, `front-behind`), for the parts drawn. */
export type Worn = {
    slot: ItemSlot;
    slug: string;
    art: Partial<Record<string, string>>;
};

export type AvatarLook = {
    body_color: string;
    hair: string;
    worn: Worn[];
} | null;

type Animation = { frames: number[]; frame_ms?: number };

/**
 * How avatars are drawn (resources/art/avatars/rig.json, docs/assets-plan.md
 * §14), in game pixels, with the art every resident shares.
 */
export type AvatarRig = {
    frame: [number, number];
    frames: number;
    animations: {
        idle: Animation;
        walk: Animation;
        sit: Animation;
        wave: Animation;
    };
    /** Screen pixels walked per whole walk cycle. */
    stride_px: number;
    /** The anchor a rigid layer follows, by slot (`hair` too); null for drawn slots. */
    slots: Record<string, string | null>;
    /** Back to front, by facing: slots, `base` and `behind`. */
    layers: Record<'front' | 'back', string[]>;
    /** Each anchor's position in each frame, by facing. */
    anchors: Record<'front' | 'back', Record<string, [number, number]>[]>;
    base: Partial<Record<'front' | 'back', string>>;
    /** Hair styles' art, by style and facing. */
    hair: Record<string, Partial<Record<'front' | 'back', string>>>;
};

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
    /** Switched on: it plays its item's animations, if it has any. Anyone in the room may switch it. */
    on: boolean;
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
    /** The programme when the instance is one of the VFA's theaters; null everywhere else. */
    cinema: CinemaProgramme | null;
    /** WHSH's lineup, heard in every room: on the air now and still to come, in order. */
    broadcasts: Broadcast[];
    players: PlayerState[];
};

/** An MP3 on WHSH's lineup, from start to end in server time; it ends early where the next one starts. */
export type Broadcast = {
    id: number;
    title: string;
    url: string;
    starts_at_ms: number;
    ends_at_ms: number;
};

/** One film on the VFA's programme, from start to end in server time. */
export type Screening = {
    id: number;
    video_id: string;
    title: string;
    /** Starts at its own time; the queue flows around it. */
    premiere: boolean;
    starts_at_ms: number;
    ends_at_ms: number;
};

/** How staff have set a theater's house lights: auto dims them while a film is on. */
export type HouseLights = 'auto' | 'up' | 'dim' | 'dark';

/** What a VFA theater's screen shows: dark while switched off, else the films showing and to come, in order. */
export type CinemaProgramme = {
    open: boolean;
    lights: HouseLights;
    screenings: Screening[];
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

export type OutfitChangedEvent = Stamped & {
    user_id: number;
    avatar: AvatarLook;
};

export type FurnitureChangedEvent = Stamped & {
    furniture: PlacedFurniture[];
};

export type CinemaChangedEvent = Stamped & { cinema: CinemaProgramme };

export type BroadcastChangedEvent = Stamped & { broadcasts: Broadcast[] };

export type InstanceReassignedEvent = Stamped & {
    message: string;
    target_instance_id: number | null;
    /** Rejoin this room instead of the current one. */
    room_slug: string | null;
};

/**
 * A part of a picture that moves, a building's or a room piece's at one
 * facing: a strip of `frames` frames, each `width` × `height`, drawn with its
 * top left `x`, `y` game pixels into the picture. Frame n shows for
 * `frame_ms[n]`, then the strip loops.
 */
export type ArtAnimation = {
    url: string;
    x: number;
    y: number;
    width: number;
    height: number;
    frames: number;
    frame_ms: number[];
};

export type AssetManifest = {
    version: string;
    images: Record<'tiles' | 'resident' | 'marker' | 'hover', string>;
    /** Art for buildings that have it, by building slug. The rest are drawn as placeholders. */
    buildings: Record<string, string>;
    /** Animations drawn over building art, by building slug and part. */
    animations: Record<string, Record<string, ArtAnimation>>;
    /** Art for wall styles that have it, by style and part: `left`, `right`, `left-door`, `right-door`. */
    walls: Record<string, Record<string, string>>;
    /** Null until the build has avatar art: residents are then one tinted sprite. */
    avatar: AvatarRig | null;
    /**
     * Room pieces in slot order, as maps number them in their props tileset
     * (null for a retired piece): the tiles each covers before any turn, and
     * its art by facing for the facings drawn.
     */
    props?: Array<RoomPiece | null>;
};

/** A piece of a room's own furniture, placed in its map in Tiled, such as the Coin Laundry's washers. */
export type RoomPiece = {
    slug: string;
    wide: number;
    deep: number;
    art: Record<string, string>;
    /** What plays over each facing's art, by facing, while the piece is placed switched on. */
    animations?: Record<string, ArtAnimation>;
};

/** What a fixture on a map is for; App\Domain\World\FixtureKind on the server. */
export type FixtureKind = 'shop' | 'fishing' | 'concessions';

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
        /** What plays over a facing's art while a placed piece is switched on, by facing; a piece with any can be switched. */
        animations?: Record<string, ArtAnimation>;
    } | null;
};

/** A resident's coins, stipend status and inventory, as every economy response carries them. */
export type Wallet = {
    balance: number;
    stipend: { amount: number; available: boolean; next_at: string };
    inventory: OwnedItem[];
    /** The pieces the resident has on. */
    worn: number[];
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
