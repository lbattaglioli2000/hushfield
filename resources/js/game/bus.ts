import type {
    CinemaProgramme,
    FishingCatch,
    FixtureKind,
    FriendsList,
    HomeInfo,
    HomeVisitors,
    HomesList,
    Item,
    PlacedFurniture,
    TownMap,
    Wallet,
} from './protocol/types';

/**
 * The only bridge between the game and whatever UI hosts it. React subscribes
 * and emits here; it never touches Phaser objects, and game state never lives in React.
 */
/** In decorate mode, the owned piece the next tap places, or moves if it is placed. */
export type ArmedPiece = { piece: number; item: Item };

export type ConnectionState = 'connecting' | 'online' | 'reconnecting';

/** Idle, asking to cast, line in and waiting, something biting, asking to reel. */
export type FishingPhase = 'idle' | 'casting' | 'waiting' | 'bite' | 'reeling';

/**
 * Push-to-talk: connecting to the instance's voice room, ready to talk, talking,
 * or unavailable (switched off, refused, or no microphone; the message says why).
 */
export type VoiceState = 'connecting' | 'ready' | 'talking' | 'unavailable';

/**
 * The VFA's screen: dark (switched off), idle (nothing showing; `next` says
 * what is coming), loading or playing a film, or unable to play the one on.
 */
export type CinemaPhase =
    | 'dark'
    | 'idle'
    | 'loading'
    | 'playing'
    | 'unavailable';

export type CinemaState = {
    phase: CinemaPhase;
    /** The film on now; null when nothing is. */
    film: { title: string; premiere: boolean } | null;
    next: { title: string; startsAtMs: number; premiere: boolean } | null;
    /** How dark the house lights have the room: 0 with them up, toward 1 with them down. */
    darkness: number;
    /** The film is playing without sound until the player presses Sound. */
    muted: boolean;
    /** Some of the TV is on the glass; a film plays only then. */
    inView: boolean;
};

/**
 * Where a room's TV is on the glass, in CSS pixels from its top-left corner:
 * the top of its left edge, its size, and how far its top and bottom drop per
 * pixel across, slanted with the wall.
 */
export type ScreenBox = {
    x: number;
    y: number;
    width: number;
    height: number;
    slope: number;
};

/** Why the game stopped for good. 'unavailable' covers boot failures and lost rooms. */
export type FatalKind =
    | 'session_moved'
    | 'please_reload'
    | 'banned'
    | 'unavailable';

export type GameEvents = {
    // Game → UI
    connection: { state: ConnectionState; message?: string };
    instance: { id: number; label: string; number: number };
    /** After every snapshot: the room the player is in, and whose home it is. */
    room: {
        slug: string;
        name: string;
        /** The room's name, or "Wren's residence" in a home. */
        title: string;
        /** Where the tuning dial points, in MHz; null leaves it off-station. */
        frequency: number | null;
        home: HomeInfo | null;
        /** The player is standing in their own home and may decorate it. */
        ownHome: boolean;
    };
    /** The current home's arrangement, after every snapshot and change. Empty outside homes. */
    furniture: { placed: PlacedFurniture[] };
    /** The answer to a 'load-homes' or 'set-home-open'. */
    homes: HomesList;
    /** The answer to a 'load-town', or why it could not be had. */
    town: { map: TownMap } | { error: string };
    /** After entering the world, after every change to the list, and whenever a friend request arrives. */
    friends: FriendsList;
    /** The outcome of anything asked of the friends list. */
    'friends-result': { ok: boolean; message: string };
    /** The outcome of anything asked of the residences: travel, the door, furniture. */
    'home-result': { ok: boolean; message: string };
    notice: { message: string };
    fatal: { kind: FatalKind; message: string };
    'chat-error': { message: string };
    /** The player tapped another resident. */
    'player-selected': {
        userId: number;
        username: string;
        messageId: number | null;
        body: string | null;
    };
    /** The answer to a 'send-report'. */
    'report-result': { ok: boolean; message: string };
    /** After entering the world and after every purchase or stipend claim. */
    wallet: Wallet;
    /** The player walked onto a fixture on the map, such as the shop counter. */
    'use-fixture': { kind: FixtureKind };
    /** The answer to a 'load-shop'. */
    shop: { items: Item[]; open: boolean };
    /** The answer to a 'purchase-item' or 'claim-stipend', and failures of 'load-shop'. */
    'economy-result': { ok: boolean; message: string };
    /** Where the player's line is: see FishingPhase. */
    fishing: { phase: FishingPhase };
    /** How a reel went (a landed catch, or one that got away), or why a cast or reel was refused. */
    'fishing-result': {
        ok: boolean;
        message: string;
        catch: FishingCatch | null;
        paid: number;
    };
    /** Where push-to-talk stands; a message explains a refusal. Only for adults. */
    voice: { state: VoiceState; message?: string };
    /** After every snapshot and programme change: the VFA's programme in the cinema, null anywhere else. */
    cinema: { programme: CinemaProgramme | null };
    /** What the VFA's screen is doing, for the set to show around it. Null outside the cinema. */
    'cinema-state': { state: CinemaState | null };
    /** Every frame it moves: where the room's TV is on the glass, and whether any of it is; null in a room without one. */
    'cinema-screen-rect': { rect: ScreenBox | null; inView: boolean };
    /** The player walked off while their line was in the water; it is abandoned. */
    'fishing-interrupted': Record<string, never>;

    // UI → Game
    /** TALK pressed (true) or let go (false). */
    'voice-talk': { active: boolean };
    /** The Volume knob, 0–1: voices play at this share of their distance volume, and the VFA's film at this volume. */
    'voice-volume': { volume: number };
    /** The layer over the glass the VFA's film plays in, laid over the screen in the room; null once the set is gone. */
    'cinema-screen': { element: HTMLElement | null };
    /** Sound pressed: a click, so the browser lets the film play aloud. Handled before the click returns. */
    'cinema-sound': Record<string, never>;
    'send-chat': { body: string };
    'send-report': {
        userId: number;
        messageId: number | null;
        reason: string;
    };
    'load-shop': Record<string, never>;
    'purchase-item': { itemId: number };
    'claim-stipend': Record<string, never>;
    /** Put a line in the water, or reel the one that is in. The server judges both. */
    'cast-line': Record<string, never>;
    'reel-in': Record<string, never>;
    /** Go to a room: the residence with no instance is the player's own home. */
    travel: { slug: string; instanceId: number | null };
    'load-homes': Record<string, never>;
    /** The town map, with fresh counts, for when the Map key opens it. */
    'load-town': Record<string, never>;
    'set-home-visitors': { visitors: HomeVisitors };
    /** Fresh locations and requests, for when the friends list opens. */
    'load-friends': Record<string, never>;
    'send-friend-request': { username: string };
    'answer-friend-request': { requestId: number; accept: boolean };
    'remove-friend': { userId: number };
    'set-friend-requests': { allowed: boolean };
    /** In decorate mode taps place the armed item, or put away the piece tapped, instead of walking. */
    decorate: { active: boolean; armed: ArmedPiece | null };
    /** Turn a placed piece a quarter turn clockwise, where it stands. */
    'turn-furniture': { piece: number };
    /** No payload: any input that keeps the player from being shown as away. */
    'input-activity': Record<string, never>;
};

type Handler<T> = (payload: T) => void;

export class EventBus {
    private handlers = new Map<keyof GameEvents, Set<Handler<never>>>();

    on<K extends keyof GameEvents>(
        event: K,
        handler: Handler<GameEvents[K]>,
    ): () => void {
        let set = this.handlers.get(event);

        if (!set) {
            set = new Set();
            this.handlers.set(event, set);
        }

        set.add(handler as Handler<never>);

        return () => set.delete(handler as Handler<never>);
    }

    emit<K extends keyof GameEvents>(event: K, payload: GameEvents[K]): void {
        this.handlers
            .get(event)
            ?.forEach((handler) =>
                (handler as Handler<GameEvents[K]>)(payload),
            );
    }

    clear(): void {
        this.handlers.clear();
    }
}
