import type {
    Participant,
    RemoteAudioTrack,
    RemoteTrack,
    RemoteTrackPublication,
    TrackPublication,
} from 'livekit-client';
import { Room, RoomEvent, Track } from 'livekit-client';
import type { EventBus, VoiceState } from './bus';
import type { Copy } from './copy';
import { RefusedError, userMessage } from './net/http';
import type { WorldNet } from './net/WorldNet';
import { RadioVoice, squelch } from './voiceRadio';

type Point = { x: number; y: number };

/** Where everyone in the room stands now, in tiles; others keyed by user id. */
export type VoicePositions = { self: Point | null; others: Map<number, Point> };

/** What voice needs from the scene: positions to fade by, and avatars to light up. */
export type VoiceView = {
    voicePositions(): VoicePositions;
    setOnAir(userId: number, on: boolean): void;
};

/** Within this many tiles a voice is at full volume. */
const NEAR_TILES = 2;
/** How often voices are re-faded as people walk. */
const FADE_MS = 150;
/** Before rejoining a voice room the connection to was lost (or a moderator's mute dropped). */
const REJOIN_MS = 2000;

/**
 * How loud a voice is at this distance: full nearby, then easing to silence at
 * the edge of hearing.
 */
export function hearingVolume(tiles: number, hearingTiles: number): number {
    if (tiles <= NEAR_TILES) return 1;
    if (tiles >= hearingTiles) return 0;

    const left = (hearingTiles - tiles) / (hearingTiles - NEAR_TILES);

    return left * left;
}

/**
 * Proximity voice for adults: joins the current instance's LiveKit room to
 * listen, and opens the microphone only while TALK is held. Every voice comes
 * through the radio effect, faded by how far away its speaker stands; the
 * scene lights up whoever is on air. Returns the cleanup.
 */
export function connectVoice(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
    hearingTiles: number,
    scene: () => VoiceView | null,
): () => void {
    const context = new AudioContext();
    const radios = new Map<string, RadioVoice>();
    const audible = new Set<HTMLMediaElement>();
    const airing = new Set<number>();
    let room: Room | null = null;
    let instanceId: number | null = null;
    let canSpeak = false;
    let holding = false;
    let talking = false;
    let volume = 0.5;
    let destroyed = false;
    let rejoin: ReturnType<typeof setTimeout> | null = null;
    // Microphone changes run one after another, so a quick tap can't leave it open.
    let mic: Promise<unknown> = Promise.resolve();

    const set = (state: VoiceState, message?: string) =>
        bus.emit('voice', { state, message });
    const userIdOf = (participant: Participant) => Number(participant.identity);
    const onAir = (userId: number, on: boolean) => {
        if (on) airing.add(userId);
        else airing.delete(userId);

        scene()?.setOnAir(userId, on);
    };

    const leave = () => {
        if (rejoin) clearTimeout(rejoin);
        rejoin = null;

        const old = room;
        room = null;
        talking = false;

        if (old) {
            old.removeAllListeners();
            void old.disconnect();
        }

        radios.forEach((radio) => radio.dispose());
        radios.clear();
        audible.forEach((element) => element.remove());
        audible.clear();
        [...airing].forEach((userId) => onAir(userId, false));
    };

    const rejoinLater = (id: number) => {
        if (destroyed || instanceId !== id) return;

        rejoin = setTimeout(() => void join(id), REJOIN_MS);
    };

    const join = async (id: number) => {
        leave();
        set('connecting');

        let grant;

        try {
            grant = await net.voiceToken();
        } catch (error) {
            // Refused (a minor, voice switched off): stay off. Anything else may pass.
            if (instanceId !== id || destroyed) return;

            set('unavailable', userMessage(error, copy));

            if (!(error instanceof RefusedError) || error.status >= 500) {
                rejoinLater(id);
            }

            return;
        }

        if (destroyed || instanceId !== id) return;

        canSpeak = grant.can_speak;
        const next = new Room({
            webAudioMix: { audioContext: context },
            adaptiveStream: false,
            dynacast: false,
        });
        room = next;
        listen(next, id);

        try {
            await next.connect(grant.url, grant.token);
        } catch {
            if (room !== next) return;

            room = null;
            next.removeAllListeners();
            set('unavailable', copy.reconnecting);
            rejoinLater(id);

            return;
        }

        if (room !== next) {
            void next.disconnect();

            return;
        }

        set('ready');

        if (holding) startTalking();
    };

    const listen = (next: Room, id: number) => {
        next.on(
            RoomEvent.TrackSubscribed,
            (
                track: RemoteTrack,
                publication: RemoteTrackPublication,
                participant,
            ) => {
                if (track.kind !== Track.Kind.Audio) return;

                // LiveKit plays through the audio context; the element only keeps the stream flowing.
                const element = track.attach();
                audible.add(element);
                document.body.appendChild(element);

                const radio = new RadioVoice(context);
                (track as RemoteAudioTrack).setWebAudioPlugins(radio.nodes);
                radios.set(publication.trackSid, radio);

                if (!publication.isMuted) {
                    radio.keyUp();
                    onAir(userIdOf(participant), true);
                }
            },
        )
            .on(
                RoomEvent.TrackUnsubscribed,
                (track: RemoteTrack, publication, participant) => {
                    track.detach().forEach((element) => {
                        audible.delete(element);
                        element.remove();
                    });
                    radios.get(publication.trackSid)?.dispose();
                    radios.delete(publication.trackSid);
                    onAir(userIdOf(participant), false);
                },
            )
            .on(
                RoomEvent.TrackUnmuted,
                (publication: TrackPublication, participant) => {
                    if (participant === next.localParticipant) return;

                    radios.get(publication.trackSid)?.keyUp();
                    onAir(userIdOf(participant), true);
                },
            )
            .on(
                RoomEvent.TrackMuted,
                (publication: TrackPublication, participant) => {
                    if (participant === next.localParticipant) return;

                    radios.get(publication.trackSid)?.keyDown();
                    onAir(userIdOf(participant), false);
                },
            )
            .on(RoomEvent.ParticipantDisconnected, (participant) =>
                onAir(userIdOf(participant), false),
            )
            // Dropped by the server (a mute rejoins listen-only) or a lost connection.
            .on(RoomEvent.Disconnected, () => {
                if (room !== next) return;

                leave();
                set('connecting');
                rejoinLater(id);
            });
    };

    const startTalking = () => {
        const current = room;

        if (!current || talking) return;

        if (!canSpeak) {
            set('ready', copy.voice_muted);

            return;
        }

        talking = true;
        set('talking');
        squelch(context, volume);
        onAir(userIdOf(current.localParticipant), true);
        mic = mic
            .then(() =>
                current.localParticipant.setMicrophoneEnabled(true, {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                }),
            )
            .catch(() => {
                if (room !== current) return;

                talking = false;
                onAir(userIdOf(current.localParticipant), false);
                set('ready', copy.voice_mic_blocked);
            });
    };

    const stopTalking = () => {
        const current = room;

        if (!current || !talking) return;

        talking = false;
        set('ready');
        squelch(context, volume);
        onAir(userIdOf(current.localParticipant), false);
        mic = mic
            .then(() => current.localParticipant.setMicrophoneEnabled(false))
            .catch(() => undefined);
    };

    // Browsers hold audio back until the player has touched the page.
    const unlock = () => {
        void context.resume();

        if (room && !room.canPlaybackAudio) void room.startAudio();
    };
    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);

    const fade = setInterval(() => {
        const view = scene();

        if (!room || !view) return;

        const { self, others } = view.voicePositions();

        for (const participant of room.remoteParticipants.values()) {
            const at = others.get(userIdOf(participant));
            const tiles =
                self && at
                    ? Math.hypot(at.x - self.x, at.y - self.y)
                    : Infinity;
            participant.setVolume(hearingVolume(tiles, hearingTiles) * volume);
        }
    }, FADE_MS);

    const cleanups = [
        bus.on('instance', ({ id }) => {
            if (id === instanceId) return;

            instanceId = id;
            void join(id);
        }),
        bus.on('voice-talk', ({ active }) => {
            holding = active;
            unlock();

            if (active) startTalking();
            else stopTalking();
        }),
        bus.on('voice-volume', (next) => {
            volume = next.volume;
        }),
    ];

    return () => {
        destroyed = true;
        clearInterval(fade);
        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
        cleanups.forEach((cleanup) => cleanup());
        leave();
        void context.close();
    };
}
