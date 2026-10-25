import { useState } from 'react';
import type { EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export default function InstanceLabel({ bus }: { bus: EventBus }) {
    const [label, setLabel] = useState<string | null>(null);
    useGameBus(bus, 'instance', ({ label }) => setLabel(label));

    if (!label) return null;

    return (
        <div
            className="pointer-events-none absolute top-3 left-3 rounded-md bg-glass/80 px-3 py-1 font-mono text-xs tracking-[0.15em] amber-glow uppercase"
            data-test="instance-label"
        >
            {label}
        </div>
    );
}
