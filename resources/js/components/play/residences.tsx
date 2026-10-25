import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
 * The Residences button and directory, and decorate mode in the player's own
 * home. Hidden until the game has drawn a room.
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
            <div className="flex flex-col items-start gap-2">
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            setOpen(true);
                            setResult(null);
                            bus.emit('load-homes', {});
                        }}
                        data-test="open-residences"
                    >
                        Residences
                    </Button>
                    {room.ownHome && (
                        <Button
                            size="sm"
                            variant={decorating ? 'default' : 'secondary'}
                            onClick={() => {
                                setDecorating(!decorating);
                                setArmed(null);
                            }}
                            data-test="toggle-decorate"
                        >
                            {decorating ? 'Done decorating' : 'Decorate'}
                        </Button>
                    )}
                </div>

                {decorating && (
                    <div
                        className="flex w-64 flex-col gap-2 rounded-md bg-black/60 p-3 text-sm backdrop-blur"
                        data-test="decorate-panel"
                    >
                        <p className="text-xs text-white/80">
                            {copy.decorate_hint}
                        </p>
                        {furniture.length === 0 ? (
                            <p className="text-white/80">
                                You own no furniture yet. The shop has some.
                            </p>
                        ) : (
                            <ul className="flex max-h-[45vh] flex-col gap-1 overflow-y-auto">
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
                                            className={`flex w-full items-center justify-between rounded px-2 py-1.5 text-left ${armed === item.id ? 'bg-amber-200 text-[#1d2330]' : 'hover:bg-white/10'}`}
                                            aria-pressed={armed === item.id}
                                            data-test={`arm-${item.slug}`}
                                        >
                                            <span>{item.name}</span>
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
                    </div>
                )}

                {!open && result && !result.ok && (
                    <p
                        role="status"
                        className="rounded-md bg-red-950/80 px-3 py-2 text-sm"
                    >
                        {result.message}
                    </p>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent data-test="residences-panel">
                    <DialogHeader>
                        <DialogTitle>{copy.residences_title}</DialogTitle>
                        <DialogDescription>
                            {copy.residences_intro}
                        </DialogDescription>
                    </DialogHeader>

                    {result && (
                        <p
                            role="status"
                            className={`rounded-md px-3 py-2 text-sm ${result.ok ? 'bg-emerald-950/80 text-white' : 'bg-red-950/80 text-white'}`}
                        >
                            {result.message}
                        </p>
                    )}

                    {homes === null ? (
                        <p className="text-sm text-muted-foreground">
                            Knocking…
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    disabled={!homes.residence || room.ownHome}
                                    onClick={() => travel(null)}
                                    data-test="go-home"
                                >
                                    {room.ownHome ? "You're home" : 'Go home'}
                                </Button>
                            </div>

                            {homes.own && (
                                <fieldset
                                    className="grid gap-2"
                                    data-test="door-setting"
                                >
                                    <legend className="mb-1 text-sm font-medium">
                                        Who may visit your home
                                    </legend>
                                    <div className="flex flex-wrap gap-2">
                                        {VISITOR_OPTIONS.map(
                                            ({ value, label }) => (
                                                <Button
                                                    key={value}
                                                    size="sm"
                                                    variant={
                                                        homes.own?.visitors ===
                                                        value
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    aria-pressed={
                                                        homes.own?.visitors ===
                                                        value
                                                    }
                                                    onClick={() =>
                                                        bus.emit(
                                                            'set-home-visitors',
                                                            { visitors: value },
                                                        )
                                                    }
                                                    data-test={`door-${value}`}
                                                >
                                                    {label}
                                                </Button>
                                            ),
                                        )}
                                    </div>
                                </fieldset>
                            )}

                            <div className="grid gap-2">
                                <p className="text-sm font-medium">
                                    Doors you may knock on
                                </p>
                                {homes.open.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {copy.residences_empty}
                                    </p>
                                ) : (
                                    <ul className="grid max-h-[40vh] gap-2 overflow-y-auto">
                                        {homes.open.map((home) => (
                                            <li
                                                key={home.instance_id}
                                                className="flex items-center justify-between rounded-lg border px-3 py-2"
                                                data-test="open-home"
                                            >
                                                <span>
                                                    {home.owner}
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {home.visitors} inside
                                                        {home.friends_only &&
                                                            ' · friends only'}
                                                    </span>
                                                </span>
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        travel(home.instance_id)
                                                    }
                                                >
                                                    Visit
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
