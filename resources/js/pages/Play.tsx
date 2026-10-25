import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ChatBar from '@/components/play/chat-bar';
import ConnectionBanner from '@/components/play/connection-banner';
import EconomyHud from '@/components/play/economy-hud';
import FatalScreen from '@/components/play/fatal-screen';
import Friends from '@/components/play/friends';
import InstanceLabel from '@/components/play/instance-label';
import Notices from '@/components/play/notices';
import OnAirTicker from '@/components/play/on-air-ticker';
import PlayerCard from '@/components/play/player-card';
import Residences from '@/components/play/residences';
import { SignalTray } from '@/components/play/signal-tray';
import TownMap from '@/components/play/town-map';
import TuningDial, { Static, stationKey } from '@/components/play/tuning-dial';
import BakelitePanel from '@/components/radio/bakelite-panel';
import Knob from '@/components/radio/knob';
import RadioKey from '@/components/radio/radio-key';
import type { GameBootProps, GameEvents } from '@/game';
import { EventBus, startGame } from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';
import { useRadioSound } from '@/hooks/use-radio-sound';

type PlayProps = { game: GameBootProps };

/** The band switch has no other bands behind it yet; FM stays latched. */
const BANDS = ['FM', 'AM', '3AM'];

/**
 * The game shell: The Listener, Model 3B. Phaser owns the canvas behind the
 * glass; React draws the set around it and talks to the game only through the bus.
 */
export default function Play({ game }: PlayProps) {
    const parent = useRef<HTMLDivElement>(null);
    const [bus] = useState(() => new EventBus());
    // Boot props are read once: a props refresh must not tear down and reboot a running game.
    const boot = useRef(game);
    const [room, setRoom] = useState<GameEvents['room'] | null>(null);
    const [retune, setRetune] = useState(0);
    const [tray, setTray] = useState<HTMLDivElement | null>(null);
    const { volume, setVolume, crackle } = useRadioSound();

    // Every snapshot repeats the room; only a different station moves the needle.
    useGameBus(bus, 'room', (next) => {
        setRoom(next);

        if (!room || stationKey(room) !== stationKey(next)) {
            setRetune((count) => count + 1);
            crackle();
        }
    });

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
            <Head title={room?.title ?? 'Hushfield'} />
            <main className="fixed inset-0 flex min-h-[640px] min-w-[1024px] wood p-4 select-none">
                <SignalTray value={tray}>
                    <BakelitePanel className="flex min-w-0 flex-1 flex-col gap-4 p-5">
                        <TuningDial
                            stations={game.stations}
                            room={room}
                            retune={retune}
                        />

                        <div className="flex min-h-0 flex-1 gap-5">
                            {/* The bezel: the ticker, the glass, and the talk strip. */}
                            <div className="flex min-w-0 flex-1 flex-col gap-3 rounded-2xl bg-walnut-deep p-3 shadow-[inset_0_2px_6px_rgb(0_0_0/0.6)]">
                                <OnAirTicker copy={game.copy} />
                                <div className="relative min-h-0 flex-1 overflow-hidden rounded-lg bg-glass">
                                    <div
                                        ref={parent}
                                        className="absolute inset-0 touch-none"
                                    />
                                    {retune > 0 && <Static key={retune} />}
                                    {/* The glass itself: a soft vignette and a sheen, over the game. */}
                                    <div
                                        aria-hidden
                                        className="pointer-events-none absolute inset-0 rounded-lg bg-[linear-gradient(160deg,rgb(255_255_255/0.06),transparent_35%)] shadow-[inset_0_0_40px_rgb(0_0_0/0.55)]"
                                    />
                                    <InstanceLabel bus={bus} />
                                    <div
                                        ref={setTray}
                                        className="absolute top-3 right-3 flex max-h-[calc(100%-1.5rem)] w-80 flex-col gap-2 overflow-y-auto"
                                        data-test="signal-tray"
                                    />
                                </div>
                                <ChatBar
                                    bus={bus}
                                    maxLength={game.chatMaxLength}
                                    placeholder={game.copy.chat_placeholder}
                                />
                            </div>

                            {/* The right of the set: speaker, knobs, band switch, and the panel keys. */}
                            <div className="flex w-72 shrink-0 flex-col gap-4">
                                <div
                                    aria-hidden
                                    className="min-h-16 flex-1 rounded-xl grille"
                                />
                                <div className="flex justify-around">
                                    <Knob
                                        label="Volume"
                                        value={volume}
                                        onChange={setVolume}
                                    />
                                    <Knob
                                        label="Lamp"
                                        sublabel="No signal"
                                        value={0.2}
                                        disabled
                                    />
                                </div>
                                <div
                                    className="grid grid-cols-3 gap-2"
                                    title="No signal"
                                >
                                    {BANDS.map((band) => (
                                        <RadioKey
                                            key={band}
                                            size="sm"
                                            disabled
                                            aria-pressed={band === 'FM'}
                                            className="disabled:opacity-100 aria-[pressed=false]:opacity-60"
                                        >
                                            {band}
                                        </RadioKey>
                                    ))}
                                </div>
                                <div
                                    className="grid grid-cols-2 gap-x-2 gap-y-3"
                                    data-test="panel-keys"
                                >
                                    <EconomyHud bus={bus} copy={game.copy} />
                                    <Friends bus={bus} copy={game.copy} />
                                    <TownMap bus={bus} copy={game.copy} />
                                    <Residences bus={bus} copy={game.copy} />
                                </div>
                            </div>
                        </div>
                    </BakelitePanel>

                    <ConnectionBanner bus={bus} />
                    <Notices bus={bus} />
                    <PlayerCard bus={bus} reasons={game.reportReasons} />
                    <FatalScreen bus={bus} copy={game.copy} />
                </SignalTray>
            </main>
        </>
    );
}
