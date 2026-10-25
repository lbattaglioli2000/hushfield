import { MessageCircle, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import type { EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export default function ChatBar({
    bus,
    maxLength,
}: {
    bus: EventBus;
    maxLength: number;
}) {
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    useGameBus(bus, 'chat-error', ({ message }) => setError(message));

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            // Only from the game itself: Enter on a focused button or dialog control is theirs.
            const fromGame =
                event.target === document.body ||
                event.target instanceof HTMLCanvasElement;

            if (event.key === 'Enter' && fromGame) {
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
