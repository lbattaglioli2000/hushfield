import { Head } from '@inertiajs/react';
import { MessageCircle, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ConnectionState, GameEvents } from '@/game';
import { EventBus, startGame } from '@/game';
import type { RealtimeConfig } from '@/game/net';

type PlayProps = {
    game: {
        protocol: number;
        roomSlug: string;
        maxPathSteps: number;
        realtime: RealtimeConfig;
        heartbeatSeconds: number;
        afkSeconds: number;
        chatMaxLength: number;
        bubbleSeconds: number;
        manifestUrl: string;
        reportReasons: { value: string; label: string }[];
        copy: Record<string, string>;
    };
};

type Fatal = GameEvents['fatal'];
type ReportTarget = GameEvents['report-target'];

/**
 * The game shell. Phaser owns the canvas; React only draws overlays and talks to
 * the game through the event bus.
 */
export default function Play({ game }: PlayProps) {
    const parent = useRef<HTMLDivElement>(null);
    const [bus] = useState(() => new EventBus());

    useEffect(() => {
        if (!parent.current) return;

        const handle = startGame(
            parent.current,
            {
                baseUrl: '',
                roomSlug: game.roomSlug,
                manifestUrl: game.manifestUrl,
                heartbeatSeconds: game.heartbeatSeconds,
                afkSeconds: game.afkSeconds,
                bubbleSeconds: game.bubbleSeconds,
                maxPathSteps: game.maxPathSteps,
                realtime: game.realtime,
                copy: game.copy,
            },
            bus,
        );

        // Runs on unmount, on navigation away, and between React's dev double-mount.
        return () => handle.destroy();
    }, [bus, game]);

    return (
        <>
            <Head title="Town Square" />
            <main className="fixed inset-0 overflow-hidden bg-[#1d2330] text-[#f4efe4] select-none">
                <div ref={parent} className="absolute inset-0 touch-none" />
                <InstanceLabel bus={bus} />
                <ConnectionBanner bus={bus} />
                <Notices bus={bus} />
                <ChatBar
                    bus={bus}
                    maxLength={game.chatMaxLength}
                />
                <ReportDialog bus={bus} reasons={game.reportReasons} />
                <FatalScreen bus={bus} copy={game.copy} />
            </main>
        </>
    );
}

function useBus<K extends keyof GameEvents>(
    bus: EventBus,
    event: K,
    handler: (payload: GameEvents[K]) => void,
): void {
    const latest = useRef(handler);
    latest.current = handler;

    useEffect(
        () => bus.on(event, (payload) => latest.current(payload)),
        [bus, event],
    );
}

function InstanceLabel({ bus }: { bus: EventBus }) {
    const [label, setLabel] = useState<string | null>(null);
    useBus(bus, 'instance', ({ label }) => setLabel(label));

    if (!label) return null;

    return (
        <div
            className="absolute top-3 left-3 rounded-full bg-black/45 px-3 py-1 text-sm font-medium backdrop-blur"
            data-test="instance-label"
        >
            {label}
        </div>
    );
}

function ConnectionBanner({ bus }: { bus: EventBus }) {
    const [state, setState] = useState<ConnectionState>('connecting');
    const [message, setMessage] = useState<string | null>(null);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useBus(bus, 'connection', ({ state, message }) => {
        if (hideTimer.current) clearTimeout(hideTimer.current);
        setState(state);
        setMessage(message ?? null);

        if (state === 'online' && message) {
            hideTimer.current = setTimeout(() => setMessage(null), 3000);
        }
    });

    if (!message || (state === 'online' && !message)) return null;

    return (
        <div
            role="status"
            className="pointer-events-none absolute top-3 left-1/2 -translate-x-1/2 rounded-full bg-[#fbf7ee] px-4 py-1.5 text-sm text-[#1d2330] shadow-lg"
            data-test="connection-banner"
            data-state={state}
        >
            {message}
        </div>
    );
}

function Notices({ bus }: { bus: EventBus }) {
    const [notice, setNotice] = useState<{ key: number; text: string } | null>(
        null,
    );

    useBus(bus, 'notice', ({ message }) =>
        setNotice({ key: Date.now(), text: message }),
    );

    useEffect(() => {
        if (!notice) return;

        const timer = setTimeout(() => setNotice(null), 4000);

        return () => clearTimeout(timer);
    }, [notice]);

    if (!notice) return null;

    return (
        <div
            key={notice.key}
            role="status"
            className="pointer-events-none absolute top-14 left-1/2 max-w-[90vw] -translate-x-1/2 rounded-md bg-black/60 px-4 py-2 text-center text-sm backdrop-blur"
            data-test="notice"
        >
            {notice.text}
        </div>
    );
}

function ChatBar({ bus, maxLength }: { bus: EventBus; maxLength: number }) {
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    useBus(bus, 'chat-error', ({ message }) => setError(message));

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Enter' && document.activeElement !== input.current) {
                event.preventDefault();
                setOpen(true);
                input.current?.focus();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const send = () => {
        const text = body.trim();

        if (text) bus.emit('send-chat', { body: text });

        setBody('');
        setError(null);
    };

    return (
        <div className="absolute inset-x-0 bottom-0 flex flex-col items-center gap-1 p-3">
            {error && (
                <p className="rounded bg-black/60 px-3 py-1 text-sm">{error}</p>
            )}
            <form
                className="flex w-full max-w-xl items-center gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    send();
                }}
            >
                {/* On phones the chat button opens the keyboard; on desktop the field is always there. */}
                <Button
                    type="button"
                    size="icon"
                    variant="secondary"
                    className="md:hidden"
                    aria-label="Chat"
                    onClick={() => {
                        setOpen(true);
                        requestAnimationFrame(() => input.current?.focus());
                    }}
                >
                    <MessageCircle />
                </Button>
                <input
                    ref={input}
                    value={body}
                    maxLength={maxLength}
                    onChange={(event) => {
                        setBody(event.target.value);
                        bus.emit('input-activity', {});
                    }}
                    onBlur={() => body === '' && setOpen(false)}
                    placeholder="Say something…"
                    aria-label="Chat message"
                    enterKeyHint="send"
                    autoComplete="off"
                    className={`${open ? 'flex' : 'hidden md:flex'} h-10 flex-1 rounded-full border border-white/15 bg-black/45 px-4 text-base text-[#f4efe4] backdrop-blur outline-none placeholder:text-white/50 focus:border-white/40 md:text-sm`}
                    data-test="chat-input"
                />
                <Button
                    type="submit"
                    size="icon"
                    variant="secondary"
                    aria-label="Send"
                    className={open ? 'flex' : 'hidden md:flex'}
                >
                    <Send />
                </Button>
            </form>
        </div>
    );
}

function ReportDialog({
    bus,
    reasons,
}: {
    bus: EventBus;
    reasons: { value: string; label: string }[];
}) {
    const [target, setTarget] = useState<ReportTarget | null>(null);
    const [reason, setReason] = useState('');
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(
        null,
    );
    const [sending, setSending] = useState(false);

    useBus(bus, 'report-target', (next) => {
        setTarget(next);
        setReason('');
        setResult(null);
    });
    useBus(bus, 'report-result', (next) => {
        setSending(false);
        setResult(next);
    });

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && setTarget(null)}
        >
            <DialogContent data-test="report-dialog">
                <DialogHeader>
                    <DialogTitle>Report {target?.username}</DialogTitle>
                    <DialogDescription>
                        Moderators will review this player&apos;s recent
                        messages. They won&apos;t be told who reported them.
                    </DialogDescription>
                </DialogHeader>

                {result?.ok ? (
                    <p className="text-sm">{result.message}</p>
                ) : (
                    <div className="grid gap-3">
                        {target?.body && (
                            <blockquote className="rounded-md border-l-4 bg-muted px-3 py-2 text-sm">
                                {target.body}
                            </blockquote>
                        )}
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                What is wrong?
                            </legend>
                            {reasons.map((option) => (
                                <label
                                    key={option.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="reason"
                                        value={option.value}
                                        checked={reason === option.value}
                                        onChange={() => setReason(option.value)}
                                    />
                                    {option.label}
                                </label>
                            ))}
                        </fieldset>
                        {result && !result.ok && (
                            <p className="text-sm text-destructive">
                                {result.message}
                            </p>
                        )}
                    </div>
                )}

                <DialogFooter>
                    {result?.ok ? (
                        <Button onClick={() => setTarget(null)}>Close</Button>
                    ) : (
                        <Button
                            disabled={!reason || sending}
                            onClick={() => {
                                if (!target) return;
                                setSending(true);
                                bus.emit('send-report', {
                                    userId: target.userId,
                                    messageId: target.messageId,
                                    reason,
                                });
                            }}
                            data-test="report-submit"
                        >
                            Send report
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function FatalScreen({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Record<string, string>;
}) {
    const [fatal, setFatal] = useState<Fatal | null>(null);
    useBus(bus, 'fatal', setFatal);

    useEffect(() => {
        if (fatal?.kind !== 'please_reload') return;

        const timer = setTimeout(() => window.location.reload(), 2500);

        return () => clearTimeout(timer);
    }, [fatal]);

    if (!fatal) return null;

    const action =
        fatal.kind === 'session_moved'
            ? copy.session_moved_action
            : fatal.kind === 'banned'
              ? copy.banned_action
              : copy.please_reload_action;

    return (
        <div
            className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-[#1d2330]/95 p-6 text-center"
            data-test={`fatal-${fatal.kind}`}
        >
            <p className="max-w-md text-xl">{fatal.message}</p>
            <p className="max-w-md text-sm text-white/70">{action}</p>
            {fatal.kind !== 'banned' && (
                <Button
                    variant="secondary"
                    onClick={() => window.location.reload()}
                >
                    Reload
                </Button>
            )}
        </div>
    );
}
