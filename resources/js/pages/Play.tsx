import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ChatBar from '@/components/play/chat-bar';
import ConnectionBanner from '@/components/play/connection-banner';
import EconomyHud from '@/components/play/economy-hud';
import FatalScreen from '@/components/play/fatal-screen';
import InstanceLabel from '@/components/play/instance-label';
import Notices from '@/components/play/notices';
import ReportDialog from '@/components/play/report-dialog';
import type { GameBootProps } from '@/game';
import { EventBus, startGame } from '@/game';

type PlayProps = { game: GameBootProps };

/**
 * The game shell. Phaser owns the canvas; React only draws overlays and talks to
 * the game through the event bus.
 */
export default function Play({ game }: PlayProps) {
    const parent = useRef<HTMLDivElement>(null);
    const [bus] = useState(() => new EventBus());
    // Boot props are read once: a props refresh must not tear down and reboot a running game.
    const boot = useRef(game);

    useEffect(() => {
        if (!parent.current) return;

        const handle = startGame(
            parent.current,
            { ...boot.current, baseUrl: '' },
            bus,
        );

        // Runs on unmount, on navigation away, and between React's dev double-mount.
        return () => handle.destroy();
    }, [bus]);

    return (
        <>
            <Head title="Town Square" />
            <main className="fixed inset-0 overflow-hidden bg-[#1d2330] text-[#f4efe4] select-none">
                <div ref={parent} className="absolute inset-0 touch-none" />
                <InstanceLabel bus={bus} />
                <EconomyHud bus={bus} copy={game.copy} />
                <ConnectionBanner bus={bus} />
                <Notices bus={bus} />
                <ChatBar bus={bus} maxLength={game.chatMaxLength} />
                <ReportDialog bus={bus} reasons={game.reportReasons} />
                <FatalScreen bus={bus} copy={game.copy} />
            </main>
        </>
    );
}
