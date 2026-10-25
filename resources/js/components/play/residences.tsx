import { useEffect, useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import SignalCard from '@/components/radio/signal-card';
import type {
    Copy,
    EventBus,
    GameEvents,
    HomesList,
    HomeVisitors,
    Item,
    PlacedFurniture,
} from '@/game';
import { useGameBus } from '@/hooks/use-game-bus';

const RESULT_MS = 5000;

const VISITOR_OPTIONS: Array<{ value: HomeVisitors; label: string }> = [
    { value: 'closed', label: 'Nobody' },
    { value: 'friends', label: 'Friends' },
    { value: 'everyone', label: 'Everyone' },
];

/**
 * The Residences and Decorate keys, the directory, and decorate mode in the
 * player's own home. Renders into the set's key grid; hidden until the game has drawn a room.
 */
export default function Residences({
    bus,
    copy,
}: {
    bus: EventBus;
    copy: Copy;
}) {
    const [room, setRoom] = useState<GameEvents['room'] | null>(null);
    const [homes, setHomes] = useState<HomesList | null>(null);
    const [open, setOpen] = useState(false);
    const [furniture, setFurniture] = useState<Item[]>([]);
    const [placed, setPlaced] = useState<PlacedFurniture[]>([]);
    const [decorating, setDecorating] = useState(false);
    const [armed, setArmed] = useState<number | null>(null);
    const [result, setResult] = useState<GameEvents['home-result'] | null>(
        null,
    );

    useGameBus(bus, 'room', (next) => {
        setRoom(next);
        setOpen(false);

        if (!next.ownHome) setDecorating(false);
    });
    useGameBus(bus, 'homes', setHomes);
    useGameBus(bus, 'home-result', setResult);
    useGameBus(bus, 'furniture', ({ placed }) => setPlaced(placed));
    useGameBus(bus, 'wallet', (wallet) =>
        setFurniture(
            wallet.inventory.filter((item) => item.slot === 'furniture'),
        ),
    );

    useEffect(() => {
        bus.emit('decorate', {
            active: decorating,
            armedItemId: decorating ? armed : null,
        });
    }, [bus, decorating, armed]);

    useEffect(() => {
        if (!result) return;

        const timer = setTimeout(() => setResult(null), RESULT_MS);

        return () => clearTimeout(timer);
    }, [result]);

    if (!room) return null;

    const travel = (instanceId: number | null) => {
        if (!homes?.residence) return;

        bus.emit('travel', { slug: homes.residence, instanceId });
    };
    const placedIds = new Set(placed.map((piece) => piece.item.id));

    return (
        <>
            <RadioKey
                onClick={() => {
                    setOpen(true);
                    setResult(null);
                    bus.emit('load-homes', {});
                }}
                data-test="open-residences"
            >
                Residences
            </RadioKey>
            {room.ownHome && (
                <RadioKey
                    className="col-span-2"
                    aria-pressed={decorating}
                    onClick={() => {
                        setDecorating(!decorating);
                        setArmed(null);
                    }}
                    data-test="toggle-decorate"
                >
                    {decorating ? 'Done decorating' : 'Decorate'}
                </RadioKey>
            )}

            <OnTray>
                {decorating && (
                    <SignalCard
                        tone="amber"
                        className="grid gap-2"
                        data-test="decorate-panel"
                    >
                        <p className="text-xs text-bakelite/70">
                            {copy.decorate_hint}
                        </p>
                        {furniture.length === 0 ? (
                            <p>You own no furniture yet. The shop has some.</p>
                        ) : (
                            <ul className="flex max-h-[40vh] flex-col gap-1 overflow-y-auto">
                                {furniture.map((item) => (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setArmed(
                                                    armed === item.id
                                                        ? null
                                                        : item.id,
                                                )
                                            }
                                            className={`flex w-full items-center justify-between rounded-sm px-2 py-1.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-amber ${armed === item.id ? 'bg-amber text-glass' : 'text-amber hover:bg-amber/10'}`}
                                            aria-pressed={armed === item.id}
                                            data-test={`arm-${item.slug}`}
                                        >
                                            <span className="font-sans font-bold tracking-wide uppercase">
                                                {item.name}
                                            </span>
                                            <span className="text-xs opacity-70">
                                                {placedIds.has(item.id)
                                                    ? 'Placed · move'
                                                    : 'Place'}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SignalCard>
                )}

                {!open && result && !result.ok && (
                    <SignalCard role="status">{result.message}</SignalCard>
                )}
            </OnTray>

            <RadioDialog
                open={open}
                onOpenChange={setOpen}
                title={copy.residences_title}
                description={copy.residences_intro}
                data-test="residences-panel"
            >
                {result && (
                    <SignalCard
                        role="status"
                        tone={result.ok ? 'amber' : 'red'}
                    >
                        {result.message}
                    </SignalCard>
                )}

                {homes === null ? (
                    <p className="text-bakelite/60">Knocking…</p>
                ) : (
                    <div className="grid gap-4">
                        <RadioKey
                            className="justify-self-start"
                            disabled={!homes.residence || room.ownHome}
                            onClick={() => travel(null)}
                            data-test="go-home"
                        >
                            {room.ownHome ? "You're home" : 'Go home'}
                        </RadioKey>

                        {homes.own && (
                            <fieldset
                                className="grid gap-2"
                                data-test="door-setting"
                            >
                                <legend className="mb-2 text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                                    Who may visit your home
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {VISITOR_OPTIONS.map(({ value, label }) => (
                                        <RadioKey
                                            key={value}
                                            size="sm"
                                            variant="glass"
                                            aria-pressed={
                                                homes.own?.visitors === value
                                            }
                                            onClick={() =>
                                                bus.emit('set-home-visitors', {
                                                    visitors: value,
                                                })
                                            }
                                            data-test={`door-${value}`}
                                        >
                                            {label}
                                        </RadioKey>
                                    ))}
                                </div>
                            </fieldset>
                        )}

                        <div className="grid gap-1">
                            <p className="border-b border-bakelite/10 pb-1 text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                                Doors you may knock on
                            </p>
                            {homes.open.length === 0 ? (
                                <p className="text-bakelite/60">
                                    {copy.residences_empty}
                                </p>
                            ) : (
                                <ul className="flex max-h-[40vh] flex-col overflow-y-auto pr-1">
                                    {homes.open.map((home) => (
                                        <li
                                            key={home.instance_id}
                                            className="flex min-h-11 items-center justify-between gap-2 py-1"
                                            data-test="open-home"
                                        >
                                            <span className="min-w-0 truncate">
                                                <span className="font-sans text-base font-bold tracking-wide amber-glow uppercase">
                                                    {home.owner}
                                                </span>
                                                <span className="ml-2 text-xs text-bakelite/60">
                                                    · {home.visitors} inside
                                                    {home.friends_only &&
                                                        ' · friends only'}
                                                </span>
                                            </span>
                                            <RadioKey
                                                size="sm"
                                                onClick={() =>
                                                    travel(home.instance_id)
                                                }
                                            >
                                                Visit
                                            </RadioKey>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                )}
            </RadioDialog>
        </>
    );
}
