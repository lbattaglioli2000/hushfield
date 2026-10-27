import { OnTray } from '@/components/play/signal-tray';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus } from '@/game';
import { useFleeting } from '@/hooks/use-fleeting';
import { useFixture } from '@/hooks/use-game-bus';

const HINT_MS = 8000;

/** The VFA's concession counter: step up to it and read the sign. Nothing is sold yet. */
export default function Concessions({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [hint, setHint] = useFleeting<true>(HINT_MS);

    useFixture(bus, 'concessions', () => setHint(true));

    return (
        <OnTray>
            {hint && (
                <SignalCard
                    tone="amber"
                    heading="Concessions"
                    data-test="concessions-hint"
                >
                    {copy.concessions_hint}
                </SignalCard>
            )}
        </OnTray>
    );
}
