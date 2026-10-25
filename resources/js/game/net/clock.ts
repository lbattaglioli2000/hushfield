/** After the first sample, corrections apply at most this many ms per ms of real time (about 1.7 ms a frame). */
const SLEW_RATE = 0.1;
/** Corrections larger than this (the system clock changed, say) apply at once. */
const SNAP_MS = 1000;
/** Samples slower than this multiple of the best round trip are ignored. */
const RTT_TOLERANCE = 1.5;

/**
 * The server's clock as seen from here: Date.now() plus an offset estimated from
 * request round trips, assuming symmetric latency.
 */
export class ServerClock {
    private offsetMs = 0;
    private targetOffsetMs = 0;
    private slewedAt = performance.now();
    private bestRttMs = Number.POSITIVE_INFINITY;

    /** Server time in epoch milliseconds, as best this client can tell. */
    now(): number {
        const realNow = performance.now();
        const step = (realNow - this.slewedAt) * SLEW_RATE;
        const gap = this.targetOffsetMs - this.offsetMs;
        this.slewedAt = realNow;
        this.offsetMs += Math.max(-step, Math.min(step, gap));

        return Date.now() + this.offsetMs;
    }

    /**
     * Takes a server timestamp from a response to a request sent at `sentAtMs`. Any sample within
     * 1.5× the best round trip so far updates the target offset, so the clock can follow drift;
     * the applied offset then slews toward it instead of shifting every avatar at once.
     */
    observe(serverMs: number, sentAtMs: number): void {
        const receivedAt = Date.now();
        const rtt = receivedAt - sentAtMs;

        if (rtt > this.bestRttMs * RTT_TOLERANCE) return;

        const first = !Number.isFinite(this.bestRttMs);
        this.bestRttMs = Math.min(this.bestRttMs, rtt);
        this.targetOffsetMs = serverMs + rtt / 2 - receivedAt;

        if (first || Math.abs(this.targetOffsetMs - this.offsetMs) > SNAP_MS) {
            this.offsetMs = this.targetOffsetMs;
        }
    }
}
