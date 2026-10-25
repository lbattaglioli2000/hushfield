import { useEffect, useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import SignalCard from '@/components/radio/signal-card';
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
        <OnTray>
            <SignalCard
                key={notice.key}
                role="status"
                className="pointer-events-none"
                data-test="notice"
            >
                {notice.text}
            </SignalCard>
        </OnTray>
    );
}
