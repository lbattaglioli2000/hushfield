/**
 * Makes a voice sound like it is coming over the set: squeezed into a radio's
 * band, lightly overdriven, with static under it while the speaker keys up and
 * a burst of squelch as they key up and let go. Built from Web Audio nodes in
 * the order LiveKit chains a track's plugins: voice → band → drive → out.
 */

/** The band a small radio speaker carries. */
const BAND_HZ = { low: 450, high: 2800 };
/** How hard the voice is driven into the soft clipper: 1 is clean, higher is crunchier. */
const DRIVE = 3;
/** The hiss under a voice while its speaker is keyed up, and the squelch burst at either end. */
const HISS = 0.05;
const SQUELCH = 0.35;
const SQUELCH_FALL_S = 0.08;
const NOISE_SECONDS = 3;
/** Crackle pops per second of noise. */
const POPS_PER_SECOND = 25;

const noiseBuffers = new WeakMap<BaseAudioContext, AudioBuffer>();

/** A few seconds of hiss with crackle in it, made once per audio context and looped. */
function staticNoise(context: BaseAudioContext): AudioBuffer {
    const cached = noiseBuffers.get(context);

    if (cached) return cached;

    const length = Math.floor(context.sampleRate * NOISE_SECONDS);
    const buffer = context.createBuffer(1, length, context.sampleRate);
    const samples = buffer.getChannelData(0);

    for (let i = 0; i < length; i++) samples[i] = (Math.random() * 2 - 1) * 0.6;

    // Pops: a sharp spike that dies away over a millisecond or two.
    for (let n = 0; n < POPS_PER_SECOND * NOISE_SECONDS; n++) {
        const at = Math.floor(Math.random() * length);
        const size = (Math.random() * 2 - 1) * 1.5;
        const decay = Math.floor(context.sampleRate * 0.0015);

        for (let i = 0; i < decay && at + i < length; i++) {
            samples[at + i] += size * (1 - i / decay) ** 2;
        }
    }

    noiseBuffers.set(context, buffer);

    return buffer;
}

function driveCurve(): Float32Array<ArrayBuffer> {
    const curve = new Float32Array(1024);

    for (let i = 0; i < curve.length; i++) {
        const x = (i / (curve.length - 1)) * 2 - 1;
        curve[i] = Math.tanh(DRIVE * x) / Math.tanh(DRIVE);
    }

    return curve;
}

/** One remote voice's radio: hand `nodes` to RemoteAudioTrack.setWebAudioPlugins. */
export class RadioVoice {
    readonly nodes: AudioNode[];
    private readonly noise: AudioBufferSourceNode;
    private readonly hiss: GainNode;

    constructor(private readonly context: AudioContext) {
        const low = context.createBiquadFilter();
        low.type = 'highpass';
        low.frequency.value = BAND_HZ.low;
        const high = context.createBiquadFilter();
        high.type = 'lowpass';
        high.frequency.value = BAND_HZ.high;
        const drive = context.createWaveShaper();
        drive.curve = driveCurve();
        drive.oversample = '2x';
        const out = context.createGain();

        // Static joins after the voice's own chain, so it fades with distance like the voice does.
        this.noise = context.createBufferSource();
        this.noise.buffer = staticNoise(context);
        this.noise.loop = true;
        const noiseBand = context.createBiquadFilter();
        noiseBand.type = 'bandpass';
        noiseBand.frequency.value = 1800;
        noiseBand.Q.value = 0.6;
        this.hiss = context.createGain();
        this.hiss.gain.value = 0;
        this.noise.connect(noiseBand).connect(this.hiss).connect(out);
        this.noise.start(0, Math.random() * NOISE_SECONDS);

        this.nodes = [low, high, drive, out];
    }

    /** The speaker pressed TALK: a burst of squelch settling into hiss. */
    keyUp(): void {
        const now = this.context.currentTime;
        this.hiss.gain.cancelScheduledValues(now);
        this.hiss.gain.setValueAtTime(SQUELCH, now);
        this.hiss.gain.setTargetAtTime(HISS, now + 0.03, SQUELCH_FALL_S);
    }

    /** The speaker let go: a short squelch tail, then silence. */
    keyDown(): void {
        const now = this.context.currentTime;
        this.hiss.gain.cancelScheduledValues(now);
        this.hiss.gain.setValueAtTime(SQUELCH * 0.8, now);
        this.hiss.gain.setTargetAtTime(0, now + 0.05, SQUELCH_FALL_S / 2);
    }

    dispose(): void {
        this.noise.stop();
        this.nodes.forEach((node) => node.disconnect());
        this.hiss.disconnect();
    }
}

/** The talker's own click of static as they press or let go of TALK, so the key feels live. */
export function squelch(context: AudioContext, volume: number): void {
    if (volume === 0) return;

    const noise = context.createBufferSource();
    noise.buffer = staticNoise(context);
    const band = context.createBiquadFilter();
    band.type = 'bandpass';
    band.frequency.value = 1800;
    const gain = context.createGain();
    const now = context.currentTime;
    gain.gain.setValueAtTime(SQUELCH * 0.5 * volume, now);
    gain.gain.setTargetAtTime(0, now + 0.04, SQUELCH_FALL_S / 2);
    noise.connect(band).connect(gain).connect(context.destination);
    noise.start(now, Math.random() * NOISE_SECONDS, 0.3);
}
