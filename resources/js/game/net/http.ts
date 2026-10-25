import type { Copy } from '../copy';

/** A request the server refused, with its machine-readable reason ('error' when it gave none). */
export class RefusedError extends Error {
    constructor(
        public readonly reason: string,
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

/** What to tell the player about a failed request: the server's own words for a refusal, otherwise a generic line. */
export function userMessage(error: unknown, copy: Copy): string {
    return error instanceof RefusedError && error.status < 500
        ? error.message
        : copy.reconnecting;
}

export type JsonResponse = {
    ok: boolean;
    status: number;
    statusText: string;
    /** The parsed body, or {} when it wasn't JSON. */
    data: Record<string, unknown>;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Plain JSON over fetch against Laravel, with the cookie, CSRF and world-session headers. */
export class Http {
    constructor(
        private readonly baseUrl: string,
        /** Per-request headers that change over time, such as the session token and socket id. */
        private readonly extraHeaders: () => Record<string, string>,
    ) {}

    headers(): Record<string, string> {
        return {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...this.extraHeaders(),
        };
    }

    async send(
        method: 'GET' | 'POST',
        path: string,
        body?: Record<string, unknown>,
    ): Promise<JsonResponse> {
        const init: RequestInit = {
            method,
            credentials: 'same-origin',
            headers: this.headers(),
        };

        if (method !== 'GET') init.body = JSON.stringify(body ?? {});

        const response = await fetch(`${this.baseUrl}${path}`, init);
        const data = (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;

        return {
            ok: response.ok,
            status: response.status,
            statusText: response.statusText,
            data,
        };
    }

    /** A POST that outlives the page; unlike sendBeacon it can carry headers. Failures are ignored. */
    sendOnUnload(path: string): void {
        void fetch(`${this.baseUrl}${path}`, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: this.headers(),
        }).catch(() => undefined);
    }

    /** For callers that need the raw response, such as the broadcasting authorizer. */
    post(path: string, body: Record<string, unknown>): Promise<Response> {
        return fetch(`${this.baseUrl}${path}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: this.headers(),
            body: JSON.stringify(body),
        });
    }
}
