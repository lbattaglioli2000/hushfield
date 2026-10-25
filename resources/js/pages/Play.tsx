import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ChatBar from '@/components/play/chat-bar';
import ConnectionBanner from '@/components/play/connection-banner';
import EconomyHud from '@/components/play/economy-hud';
import FatalScreen from '@/components/play/fatal-screen';
import Friends from '@/components/play/friends';
import InstanceLabel from '@/components/play/instance-label';
import Notices from '@/components/play/notices';
import PlayerCard from '@/components/play/player-card';
import Residences from '@/components/play/residences';
import type { GameBootProps } from '@/game';
import { EventBus, startGame } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

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
    const [title, setTitle] = useState('Hushfield');
    useGameBus(bus, 'room', (room) => setTitle(room.title));

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
            <Head title={title} />
            <main className="fixed inset-0 overflow-hidden bg-[#1d2330] text-[#f4efe4] select-none">
                <div ref={parent} className="absolute inset-0 touch-none" />
                <InstanceLabel bus={bus} />
                <EconomyHud bus={bus} copy={game.copy} />
                <div className="absolute top-12 left-3 flex items-start gap-2">
                    <Friends bus={bus} copy={game.copy} />
                    <Residences bus={bus} copy={game.copy} />
                </div>
                <ConnectionBanner bus={bus} />
                <Notices bus={bus} />
                <ChatBar bus={bus} maxLength={game.chatMaxLength} />
                <PlayerCard bus={bus} reasons={game.reportReasons} />
                <FatalScreen bus={bus} copy={game.copy} />
            </main>
        </>
    );
}
