import type { CinemaState, EventBus, ScreenBox } from './bus';
import type { WorldNet } from './net/WorldNet';
import type { CinemaProgramme, HouseLights, Screening } from './protocol/types';

/** The few parts of the YouTube IFrame Player API the screen uses. */
type YTPlayer = {
    playVideo(): void;
    pauseVideo(): void;
    seekTo(seconds: number, allowSeekAhead: boolean): void;
    getCurrentTime(): number;
    getPlayerState(): number;
    getPlaybackRate(): number;
    setPlaybackRate(rate: number): void;
    setVolume(volume: number): void;
    mute(): void;
    unMute(): void;
    destroy(): void;
};

type YTNamespace = {
    Player: new (
        element: HTMLElement,
        options: {
            host: string;
            videoId: string;
            width: string;
            height: string;
            playerVars: Record<string, string | number>;
            events: {
                onReady(): void;
                onError(): void;
                onAutoplayBlocked(): void;
            };
        },
    ) => YTPlayer;
};

declare global {
    interface Window {
        YT?: YTNamespace;
        onYouTubeIframeAPIReady?: () => void;
    }
}

/** YT.PlayerState */
const PLAYING = 1;
const PAUSED = 2;
const CUED = 5;

/**
 * How the screen keeps up with the room. Viewers drift apart by buffering,
 * ads and slow seeks; small drift is evened out by playing a touch faster or
 * slower, large drift by jumping to where everyone else is.
 */
export const SYNC = {
    tickMs: 1000,
    /** Closer than this, in seconds, is in sync. */
    deadSeconds: 0.4,
    /** Further than this, seek instead of nudging the speed. */
    nudgeSeconds: 3,
    faster: 1.05,
    slower: 0.95,
    /** A seek lands a little late; aim this far ahead. */
    seekLeadSeconds: 0.3,
    seekCooldownMs: 5000,
    /** Playing but the clock hasn't moved this long: an ad, most likely. Leave it be. */
    stalledMs: 4000,
} as const;

/** How dark each setting of the house lights makes the room; auto is dim while a film is on, up otherwise. */
export const DARKNESS = { up: 0, dim: 0.45, dark: 0.75 } as const;

export function darkness(lights: HouseLights, filmOn: boolean): number {
    if (lights === 'auto') return filmOn ? DARKNESS.dim : DARKNESS.up;

    return DARKNESS[lights];
}

/** Privacy-enhanced mode: no YouTube cookies until a viewer interacts with the player. */
const PLAYER_HOST = 'https://www.youtube-nocookie.com';

/** The film on now and the next to start, as the programme stands at `nowMs`. */
export function showing(
    screenings: Screening[],
    nowMs: number,
): { film: Screening | null; next: Screening | null } {
    return {
        film:
            screenings.find(
                (s) => s.starts_at_ms <= nowMs && nowMs < s.ends_at_ms,
            ) ?? null,
        next: screenings.find((s) => s.starts_at_ms > nowMs) ?? null,
    };
}

export type Correction = 'hold' | 'faster' | 'slower' | 'seek';

/** What to do about a player `drift` seconds ahead of the room (negative: behind). */
export function correction(drift: number): Correction {
    const distance = Math.abs(drift);

    if (distance > SYNC.nudgeSeconds) return 'seek';
    if (distance > SYNC.deadSeconds) return drift < 0 ? 'faster' : 'slower';

    return 'hold';
}

let api: Promise<YTNamespace> | null = null;

function loadApi(): Promise<YTNamespace> {
    api ??= new Promise((resolve) => {
        if (window.YT?.Player) {
            resolve(window.YT);

            return;
        }

        const previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve(window.YT as YTNamespace);
        };

        const script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        document.head.append(script);
    });

    return api;
}

/**
 * The VFA's TV: plays whatever the programme says is on, at the moment the
 * room is at, in a frame laid over the TV on the wall and slanted with it.
 * Nobody can pause it; a film starts with the sound down until the player
 * presses Sound, which browsers require. The film pauses and its frame hides
 * while the tab is hidden or the TV is wholly off the glass, and catches up
 * with the room when it is back. Returns the cleanup.
 */
export function connectCinema(net: WorldNet, bus: EventBus): () => void {
    let programme: CinemaProgramme | null = null;
    /** The layer over the glass, from the set. */
    let layer: HTMLElement | null = null;
    /** The frame the player sits in, laid over the screen. */
    let frame: HTMLDivElement | null = null;
    let rect: ScreenBox | null = null;
    let inView = false;
    let player: YTPlayer | null = null;
    /** The film the player was made for, set before the API loads so a stale load is dropped. */
    let playerFilm: Screening | null = null;
    let ready = false;
    let failed: number | null = null;
    let volume = 0.5;
    let wantsSound = false;
    let muted = true;
    let lastSeekAt = 0;
    let lastTime = -1;
    let lastAdvanceAt = 0;
    let lastState = '';
    let destroyed = false;

    const emit = (state: CinemaState | null) => {
        const key = JSON.stringify(state);

        if (key === lastState) return;

        lastState = key;
        bus.emit('cinema-state', { state });
    };

    const unmount = () => {
        player?.destroy();
        player = null;
        playerFilm = null;
        ready = false;
        frame?.remove();
        frame = null;
    };

    const place = () => {
        if (!frame || !rect) return;

        frame.style.left = `${rect.x}px`;
        frame.style.top = `${rect.y}px`;
        frame.style.width = `${rect.width}px`;
        frame.style.height = `${rect.height}px`;
        // Slanted with the wall: its sides stay upright, its top and bottom follow the wall's foot.
        frame.style.transformOrigin = '0 0';
        frame.style.transform = `skewY(${Math.atan(rect.slope)}rad)`;
    };

    /** Whether the film may be seen and heard now: all of the screen on the glass, in a visible tab. */
    const visible = () => inView && !document.hidden;

    const seekToRoom = (film: Screening) => {
        if (!player) return;

        player.seekTo(
            (net.serverNow() - film.starts_at_ms) / 1000 + SYNC.seekLeadSeconds,
            true,
        );
        player.setPlaybackRate(1);
        player.playVideo();
        lastSeekAt = Date.now();
    };

    const mount = async (film: Screening) => {
        playerFilm = film;

        const YT = await loadApi();

        if (destroyed || playerFilm !== film || !layer) return;

        frame = document.createElement('div');
        frame.className = 'absolute pointer-events-auto [&_iframe]:size-full';
        frame.dataset.test = 'cinema-player';
        place();
        const element = document.createElement('div');
        frame.append(element);
        layer.append(frame);
        muted = !wantsSound;
        lastTime = -1;

        player = new YT.Player(element, {
            host: PLAYER_HOST,
            videoId: film.video_id,
            width: '100%',
            height: '100%',
            playerVars: {
                autoplay: 1,
                mute: muted ? 1 : 0,
                start: Math.max(
                    0,
                    Math.floor((net.serverNow() - film.starts_at_ms) / 1000),
                ),
                controls: 0,
                disablekb: 1,
                fs: 0,
                rel: 0,
                iv_load_policy: 3,
                playsinline: 1,
                origin: window.location.origin,
            },
            events: {
                onReady: () => {
                    if (playerFilm !== film) return;

                    ready = true;
                    player?.setVolume(Math.round(volume * 100));
                    seekToRoom(film);
                    tick();
                },
                onError: () => {
                    failed = film.id;
                    unmount();
                    tick();
                },
                // Sound needs a click first: play without it, and offer the Sound key.
                onAutoplayBlocked: () => {
                    muted = true;
                    player?.mute();
                    player?.playVideo();
                    tick();
                },
            },
        });
    };

    /** Keeps the player in step with the room. Only while it is ready and on screen. */
    const sync = (film: Screening, nowMs: number) => {
        if (!player) return;

        const state = player.getPlayerState();

        // A viewer clicked the picture to pause it, or it was cued but never started.
        if (state === PAUSED || state === CUED) {
            seekToRoom(film);

            return;
        }

        // Buffering or starting: nothing to measure yet.
        if (state !== PLAYING) return;

        const time = player.getCurrentTime();
        const realNow = Date.now();

        if (time !== lastTime) {
            lastTime = time;
            lastAdvanceAt = realNow;
        } else if (realNow - lastAdvanceAt > SYNC.stalledMs) {
            return;
        }

        const action = correction(time - (nowMs - film.starts_at_ms) / 1000);
        const rate =
            action === 'faster'
                ? SYNC.faster
                : action === 'slower'
                  ? SYNC.slower
                  : 1;

        if (action === 'seek') {
            if (realNow - lastSeekAt > SYNC.seekCooldownMs) seekToRoom(film);
        } else if (player.getPlaybackRate() !== rate) {
            player.setPlaybackRate(rate);
        }
    };

    const tick = () => {
        if (destroyed) return;

        if (!programme) {
            unmount();
            emit(null);

            return;
        }

        const now = net.serverNow();
        const { film, next } = showing(programme.screenings, now);
        const upNext = next
            ? {
                  title: next.title,
                  startsAtMs: next.starts_at_ms,
                  premiere: next.premiere,
              }
            : null;
        const on = film ? { title: film.title, premiere: film.premiere } : null;
        const dark = darkness(programme.lights, programme.open && !!film);

        if (!programme.open) {
            unmount();
            emit({
                phase: 'dark',
                film: null,
                next: null,
                darkness: dark,
                muted,
                inView,
            });

            return;
        }

        if (!film) {
            unmount();
            emit({
                phase: 'idle',
                film: null,
                next: upNext,
                darkness: dark,
                muted,
                inView,
            });

            return;
        }

        if (failed === film.id) {
            unmount();
            emit({
                phase: 'unavailable',
                film: on,
                next: upNext,
                darkness: dark,
                muted,
                inView,
            });

            return;
        }

        if (playerFilm?.id !== film.id) {
            unmount();

            if (layer && rect && visible()) void mount(film);
        } else if (player && ready) {
            if (visible()) {
                if (frame?.style.visibility === 'hidden') {
                    frame.style.visibility = '';
                    seekToRoom(film);
                }

                sync(film, now);
            } else if (frame && frame.style.visibility !== 'hidden') {
                frame.style.visibility = 'hidden';
                player.pauseVideo();
            }
        }

        emit({
            phase:
                player && ready && player.getPlayerState() === PLAYING
                    ? 'playing'
                    : 'loading',
            film: on,
            next: upNext,
            darkness: dark,
            muted,
            inView,
        });
    };

    // No playing out of sight; on return, the tick seeks back to the room.
    const onVisibility = () => tick();

    const timer = setInterval(tick, SYNC.tickMs);
    document.addEventListener('visibilitychange', onVisibility);

    const offs = [
        bus.on('cinema', (next) => {
            programme = next.programme;
            tick();
        }),
        bus.on('cinema-screen', ({ element }) => {
            if (element === layer) return;

            unmount();
            layer = element;
            tick();
        }),
        bus.on('cinema-screen-rect', (next) => {
            const changed = next.inView !== inView;
            rect = next.rect;
            inView = next.inView;
            place();

            // Out of the room with the screen, or the screen came into or out of full view.
            if (!rect) unmount();
            if (changed) tick();
        }),
        bus.on('voice-volume', (next) => {
            volume = next.volume;

            if (ready) player?.setVolume(Math.round(volume * 100));
        }),
        // Runs inside the click, so the browser counts it as the player's own.
        bus.on('cinema-sound', () => {
            wantsSound = true;
            muted = false;

            if (player && ready) {
                player.unMute();
                player.setVolume(Math.round(volume * 100));
                player.playVideo();
            }

            tick();
        }),
    ];

    return () => {
        destroyed = true;
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        offs.forEach((off) => off());
        unmount();
    };
}
