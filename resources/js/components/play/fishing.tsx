import { useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type { Copy, EventBus, FishingPhase, GameEvents } from '@/game';
import { useFleeting } from '@/hooks/use-fleeting';
import { useFixture, useGameBus } from '@/hooks/use-game-bus';
import { cn } from '@/lib/utils';

const RESULT_MS = 7000;
const HINT_MS = 8000;

/**
 * The Cast/Reel key and what the line is doing. The key works anywhere; away
 * from the end of the Pier the server refuses, and says where to go. Renders
 * into the set's key grid, with its signals on the tray.
 */
export default function Fishing({ bus, copy }: { bus: EventBus; copy: Copy }) {
    const [phase, setPhase] = useState<FishingPhase>('idle');
    const [result, setResult] =
        useFleeting<GameEvents['fishing-result']>(RESULT_MS);
    const [hint, setHint] = useFleeting<true>(HINT_MS);

    useGameBus(bus, 'fishing', ({ phase: next }) => {
        setPhase(next);

        if (next === 'casting') {
            setResult(null);
            setHint(null);
        }
    });
    useGameBus(bus, 'fishing-result', setResult);
    useFixture(bus, 'fishing', () => setHint(true));

    const lineOut = phase === 'waiting' || phase === 'bite';

    return (
        <>
            <RadioKey
                variant={phase === 'bite' ? 'red' : 'bakelite'}
                className={cn(
                    'col-span-2',
                    phase === 'bite' && 'animate-pulse',
                )}
                disabled={phase === 'casting' || phase === 'reeling'}
                onClick={() => bus.emit(lineOut ? 'reel-in' : 'cast-line', {})}
                data-test="fishing-key"
                data-phase={phase}
            >
                {lineOut || phase === 'reeling' ? 'Reel' : 'Cast'}
            </RadioKey>

            <OnTray>
                {hint && phase === 'idle' && !result && (
                    <SignalCard
                        tone="amber"
                        heading="The end of the Pier"
                        data-test="fishing-hint"
                    >
                        {copy.fishing_hint}
                    </SignalCard>
                )}
                {phase === 'waiting' && (
                    <SignalCard
                        tone="amber"
                        heading="Line in the water"
                        level={1}
                        data-test="fishing-waiting"
                    >
                        {copy.fishing_waiting}
                    </SignalCard>
                )}
                {phase === 'bite' && (
                    <SignalCard
                        heading="Line in the water"
                        title="A bite"
                        data-test="fishing-bite"
                    >
                        {copy.fishing_bite}
                    </SignalCard>
                )}
                {result && phase === 'idle' && (
                    <SignalCard
                        role="status"
                        tone={result.ok ? 'amber' : 'red'}
                        heading={
                            result.catch ? 'Off the end of the Pier' : undefined
                        }
                        title={result.catch?.name}
                        className="grid gap-1"
                        data-test="fishing-result"
                    >
                        {result.catch && (
                            <p className="text-bakelite/70 italic">
                                {result.catch.description}
                            </p>
                        )}
                        <p>{result.message}</p>
                        {result.catch && (
                            <p
                                className="font-sans font-bold tracking-wide amber-glow"
                                data-test="fishing-paid"
                            >
                                +{result.paid}{' '}
                                {result.paid === 1 ? 'coin' : 'coins'}
                            </p>
                        )}
                    </SignalCard>
                )}
            </OnTray>
        </>
    );
}
