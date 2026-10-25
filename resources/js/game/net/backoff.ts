import { RefusedError } from './http';

const BACKOFF_MIN_MS = 1000;
const BACKOFF_MAX_MS = 30000;

/** Exponential backoff with full jitter, 1 s up to 30 s. */
export function backoffDelay(attempt: number): number {
    const ceiling = Math.min(BACKOFF_MAX_MS, BACKOFF_MIN_MS * 2 ** attempt);

    return BACKOFF_MIN_MS + Math.random() * (ceiling - BACKOFF_MIN_MS);
}

/** Network failures and refusals without a machine-readable reason (5xx, 429, 419) may succeed later. */
export function isTransient(error: unknown): boolean {
    return !(error instanceof RefusedError) || error.reason === 'error';
}

/** Retries `attempt` with backoff while it fails transiently; rethrows refusals, and stops once `stopped()` is true. */
export async function retryWhileTransient<T>(
    attempt: () => Promise<T>,
    stopped: () => boolean,
    onRetry: () => void,
): Promise<T> {
    for (let failures = 0; ; failures++) {
        if (stopped()) throw new Error('Stopped.');

        try {
            return await attempt();
        } catch (error) {
            if (stopped() || !isTransient(error)) throw error;

            onRetry();
            await new Promise((resolve) =>
                setTimeout(resolve, backoffDelay(failures)),
            );
        }
    }
}
