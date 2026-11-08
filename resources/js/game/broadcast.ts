import type { BroadcastState, EventBus } from './bus';
import type { WorldNet } from './net/WorldNet';
import type { Broadcast } from './protocol/types';
import { RadioVoice } from './voiceRadio';

/** How the set keeps up with WHSH: checked every tick, and put back in step when it drifts too far. */
const SYNC = {
    tickMs: 1000,
    /** Further than this from the town, in seconds, jump to where it is. */
    seekSeconds: 2,
    seekCooldownMs: 5000,
} as const;

/** The broadcast on the lineup that is on the air at `nowMs`, if any. */
export function airing(lineup: Broadcast[], nowMs: number): Broadcast | null {
    return (
        lineup.find((b) => b.starts_at_ms <= nowMs && nowMs < b.ends_at_ms) ??
        null
    );
}

/**
 * WHSH through the set's speaker: whatever is on the air plays in every
 * room, at the moment the town is at, at the Volume knob's level. The set has
 * the whole lineup, so a scheduled broadcast starts on its own at its time.
 * Nobody can pause it. Browsers hold the sound back until the player has
 * clicked something; until then the set offers Listen, and it starts with
 * the next click. Keeps playing in a hidden tab, as a radio would.
 *
 * It comes over the air as voices do (RadioVoice): squeezed into the set's
 * band and lightly driven, with hiss under it, and a burst of squelch as it
 * goes on and off the air. Returns the cleanup.
 */
export function connectBroadcast(net: WorldNet, bus: EventBus): () => void {
    const audio = new Audio();
    audio.preload = 'auto';
    // Web Audio hears a recording from the asset bucket, another origin, only if it was fetched with CORS.
    audio.crossOrigin = 'anonymous';
    /** Made with the first broadcast; until a click, browsers keep it suspended. */
    let speaker: {
        context: AudioContext;
        radio: RadioVoice;
        level: GainNode;
    } | null = null;
    let lineup: Broadcast[] = [];
    /** The broadcast loaded into the speaker. */
    let loaded: number | null = null;
    let blocked = false;
    let volume = 0.5;
    let lastSeekAt = 0;
    let lastState = '';

    const emit = (state: BroadcastState | null) => {
        const key = JSON.stringify(state);

        if (key === lastState) return;

        lastState = key;
        bus.emit('broadcast-state', { state });
    };

    const connectSpeaker = () => {
        if (speaker) return speaker;

        const context = new AudioContext();
        const radio = new RadioVoice(context);
        // The Volume knob turns the hiss too, so it sits after the radio.
        const level = context.createGain();
        level.gain.value = volume;
        const chain = [
            context.createMediaElementSource(audio),
            ...radio.nodes,
            level,
            context.destination,
        ];
        chain.reduce((from, to) => (from.connect(to), to));
        speaker = { context, radio, level };

        return speaker;
    };

    /** Seconds into the broadcast the town is at. */
    const position = (on: Broadcast) =>
        (net.serverNow() - on.starts_at_ms) / 1000;

    const report = () => {
        const on = airing(lineup, net.serverNow());

        emit(
            on && {
                id: on.id,
                title: on.title,
                listening:
                    !blocked &&
                    speaker?.context.state === 'running' &&
                    (!audio.paused || audio.ended),
            },
        );
    };

    /** Only reports how it went: the next tick tries again, never this, or a refusal would loop. */
    const play = () => {
        // Allowed once the player has clicked anywhere on the page; until then it stays suspended.
        void speaker?.context.resume().then(report);
        audio
            .play()
            .then(() => {
                blocked = false;
                report();
            })
            .catch((error: unknown) => {
                // Not allowed before a click; anything else (a broken file) leaves it silent too.
                blocked =
                    error instanceof DOMException &&
                    error.name === 'NotAllowedError';
                report();
            });
    };

    const tick = () => {
        const on = airing(lineup, net.serverNow());

        if (!on) {
            if (loaded !== null) {
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                loaded = null;
                speaker?.radio.keyDown();
            }

            report();

            return;
        }

        if (loaded !== on.id) {
            loaded = on.id;
            connectSpeaker().radio.keyUp();
            audio.src = on.url;
            audio.currentTime = position(on);
            lastSeekAt = Date.now();
            play();
        } else if (audio.paused && !audio.ended) {
            // Held back until a click, or paused by the device: try again where the town is now.
            audio.currentTime = position(on);
            play();
        } else if (
            !audio.paused &&
            audio.readyState >= HTMLMediaElement.HAVE_METADATA &&
            Math.abs(audio.currentTime - position(on)) > SYNC.seekSeconds &&
            Date.now() - lastSeekAt > SYNC.seekCooldownMs
        ) {
            audio.currentTime = position(on);
            lastSeekAt = Date.now();
        }

        report();
    };

    const timer = setInterval(tick, SYNC.tickMs);

    const offs = [
        bus.on('broadcast', (next) => {
            lineup = next.lineup;
            tick();
        }),
        bus.on('voice-volume', (next) => {
            volume = next.volume;

            if (speaker) speaker.level.gain.value = volume;
        }),
        // Runs inside the click, so the browser counts it as the player's own.
        bus.on('broadcast-listen', () => {
            const on = airing(lineup, net.serverNow());

            if (!on || loaded !== on.id) return;

            audio.currentTime = position(on);
            play();
        }),
    ];

    return () => {
        clearInterval(timer);
        offs.forEach((off) => off());
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        speaker?.radio.dispose();
        void speaker?.context.close();
    };
}
