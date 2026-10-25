import { useEffect, useRef, useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import RadioKey from '@/components/radio/radio-key';
import GlassInput from '@/components/radio/glass-input';
import SignalCard from '@/components/radio/signal-card';
import type { ConnectionState, EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';
import { cn } from '@/lib/utils';

const BARS = [0.35, 0.55, 0.75, 1, 0.45];
const LIT: Record<ConnectionState, number> = {
    connecting: 2,
    reconnecting: 1,
    online: 4,
};

/** The talk strip along the bottom of the glass: TALK, the signal meter, and the message line. */
export default function ChatBar({
    bus,
    maxLength,
    placeholder,
}: {
    bus: EventBus;
    maxLength: number;
    placeholder: string;
}) {
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [signal, setSignal] = useState<ConnectionState>('connecting');
    const input = useRef<HTMLInputElement>(null);

    useGameBus(bus, 'chat-error', ({ message }) => setError(message));
    useGameBus(bus, 'connection', ({ state }) => setSignal(state));

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            // Only from the game itself: Enter on a focused button or dialog control is theirs.
            const fromGame =
                event.target === document.body ||
                event.target instanceof HTMLCanvasElement;

            if (event.key === 'Enter' && fromGame) {
                event.preventDefault();
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
        <form
            className="flex h-[4.5rem] shrink-0 items-center gap-4 rounded-xl bg-bakelite px-4 shadow-[inset_0_2px_0_rgb(255_255_255/0.6),inset_0_-3px_0_rgb(0_0_0/0.1)]"
            onSubmit={(event) => {
                event.preventDefault();
                send();
            }}
        >
            <button
                type="button"
                onClick={() => input.current?.focus()}
                className="group flex flex-col items-center gap-0.5 outline-none"
                data-test="talk"
            >
                <span className="size-11 rounded-full bg-[radial-gradient(circle_at_35%_30%,#ef6a5c,var(--color-record)_55%,#8d2119)] shadow-[0_4px_0_#7e1f18,0_6px_8px_rgb(0_0_0/0.35)] transition-transform duration-75 group-focus-visible:ring-2 group-focus-visible:ring-amber group-focus-visible:ring-offset-2 group-active:translate-y-[3px] group-active:shadow-[0_1px_0_#7e1f18]" />
                <span className="text-[0.65rem] font-bold tracking-[0.2em] text-ink">
                    TALK
                </span>
            </button>

            <span
                role="img"
                aria-label={`Signal: ${signal}`}
                className="flex h-7 items-end gap-[3px]"
                data-test="signal-meter"
                data-state={signal}
            >
                {BARS.map((height, i) => (
                    <span
                        key={i}
                        className={cn(
                            'w-1.5 rounded-[1px] transition-colors',
                            i >= LIT[signal]
                                ? 'bg-ink/15'
                                : signal === 'reconnecting'
                                  ? 'bg-record'
                                  : 'bg-walnut',
                        )}
                        style={{ height: `${height * 100}%` }}
                    />
                ))}
            </span>

            <label className="relative min-w-0 flex-1">
                <span
                    aria-hidden
                    className="absolute top-1/2 left-4 -translate-y-1/2 font-mono amber-glow"
                >
                    &gt;
                </span>
                <GlassInput
                    ref={input}
                    value={body}
                    maxLength={maxLength}
                    onChange={(event) => {
                        setBody(event.target.value);
                        bus.emit('input-activity', {});
                    }}
                    placeholder={placeholder}
                    aria-label="Chat message"
                    enterKeyHint="send"
                    autoComplete="off"
                    className="pl-9"
                    data-test="chat-input"
                />
            </label>
            <RadioKey type="submit" variant="walnut">
                Send
            </RadioKey>

            {error && (
                <OnTray>
                    <SignalCard level={1}>{error}</SignalCard>
                </OnTray>
            )}
        </form>
    );
}
