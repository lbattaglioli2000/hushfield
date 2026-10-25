import { useState } from 'react';
import type { EventBus } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

export default function InstanceLabel({ bus }: { bus: EventBus }) {
    const [label, setLabel] = useState<string | null>(null);
    useGameBus(bus, 'instance', ({ label }) => setLabel(label));

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
