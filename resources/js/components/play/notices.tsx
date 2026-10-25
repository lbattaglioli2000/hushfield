import { useEffect, useState } from 'react';
import type { EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

const NOTICE_MS = 4000;

export default function Notices({ bus }: { bus: EventBus }) {
    const [notice, setNotice] = useState<{ key: number; text: string } | null>(
        null,
    );

    useGameBus(bus, 'notice', ({ message }) =>
        setNotice({ key: Date.now(), text: message }),
    );

    useEffect(() => {
        if (!notice) return;

        const timer = setTimeout(() => setNotice(null), NOTICE_MS);

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
