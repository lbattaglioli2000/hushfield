import { useEffect, useRef, useState } from 'react';

const STORAGE_KEY = 'hushfield.radio.volume';
const DEFAULT_VOLUME = 0.5;
const STATIC_SECONDS = 0.45;
/** Static is harsh at full gain; the knob's top end maps to this. */
const MAX_GAIN = 0.35;

function storedVolume(): number {
    const stored = Number(localStorage.getItem(STORAGE_KEY) ?? NaN);

    return Number.isFinite(stored)
        ? Math.min(1, Math.max(0, stored))
        : DEFAULT_VOLUME;
}

type Speaker = { context: AudioContext; master: GainNode };

/**
 * The set's speaker. Everything it plays goes through one master gain, which
 * the Volume knob turns and this browser remembers: the tuning static now,
 * and per-room music once there is some to connect to it.
 */
export function useRadioSound() {
    const [volume, setVolume] = useState(storedVolume);
    const speaker = useRef<Speaker | null>(null);

    useEffect(() => {
        localStorage.setItem(STORAGE_KEY, String(volume));

        if (speaker.current) {
            speaker.current.master.gain.value = volume * MAX_GAIN;
        }
    }, [volume]);

    useEffect(
        () => () => {
            void speaker.current?.context.close();
            speaker.current = null;
        },
        [],
    );

    /** A short burst of filtered noise, as the dial passes between stations. */
    const crackle = () => {
        if (volume === 0) return;

        if (!speaker.current) {
            const context = new AudioContext();
            const master = context.createGain();
            master.connect(context.destination);
            speaker.current = { context, master };
        }

        const { context, master } = speaker.current;
        master.gain.value = volume * MAX_GAIN;
        // Browsers keep audio suspended until the player has interacted; until then this is silent.
        void context.resume();

        const length = Math.floor(context.sampleRate * STATIC_SECONDS);
        const buffer = context.createBuffer(1, length, context.sampleRate);
        const samples = buffer.getChannelData(0);

        for (let i = 0; i < length; i++) samples[i] = Math.random() * 2 - 1;

        const noise = context.createBufferSource();
        noise.buffer = buffer;
        const band = context.createBiquadFilter();
        band.type = 'bandpass';
        band.frequency.value = 1800;
        band.Q.value = 0.7;
        const envelope = context.createGain();
        const now = context.currentTime;
        envelope.gain.setValueAtTime(0, now);
        envelope.gain.linearRampToValueAtTime(1, now + 0.04);
        envelope.gain.exponentialRampToValueAtTime(0.001, now + STATIC_SECONDS);

        noise.connect(band).connect(envelope).connect(master);
        noise.start(now);
    };

    return { volume, setVolume, crackle };
}
