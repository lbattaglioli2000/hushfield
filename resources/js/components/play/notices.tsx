import { OnTray } from '@/components/play/signal-tray';
import SignalCard from '@/components/radio/signal-card';
import type { EventBus } from '@/game';
import { useFleeting } from '@/hooks/use-fleeting';
import { useGameBus } from '@/hooks/use-game-bus';

const NOTICE_MS = 4000;

export default function Notices({ bus }: { bus: EventBus }) {
    const [notice, setNotice] = useFleeting<{ key: number; text: string }>(
        NOTICE_MS,
    );

    useGameBus(bus, 'notice', ({ message }) =>
        setNotice({ key: Date.now(), text: message }),
    );

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
